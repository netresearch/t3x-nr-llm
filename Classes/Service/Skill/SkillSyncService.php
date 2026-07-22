<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\InjectionSeverity;
use Netresearch\NrLlm\Domain\Enum\SkillAuditEvent;
use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Domain\ValueObject\InjectionScanResult;
use Netresearch\NrLlm\Domain\ValueObject\ParsedSkill;
use Netresearch\NrLlm\Domain\ValueObject\SyncResult;
use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\Exception\HostNotAllowedException;
use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class SkillSyncService
{
    use ErrorMessageSanitizerTrait;

    /**
     * A SYNCING lock whose heartbeat (lastSynced) is older than this many seconds is considered
     * stale and may be reclaimed. A live sync renews its heartbeat every {@see $heartbeatSeconds}
     * while collecting, so this window only has to outlast one heartbeat gap plus a single slow
     * HTTP fetch — not the whole sync — which is why it can be well below the per-sync time bound.
     */
    private const STALE_LOCK_SECONDS = 180;

    /** Claude Code convention path of a marketplace index inside a repository. */
    private const MARKETPLACE_INDEX_PATH = '.claude-plugin/marketplace.json';

    private int $syncDeadline = 0;
    private int $filesProcessed = 0;
    private bool $boundsExceeded = false;
    private int $lastHeartbeat = 0;

    /**
     * @param int $maxFiles         Hard ceiling on files fetched per sync; prevents a huge repo/marketplace
     *                              from running unbounded (constructor-injectable so tests can lower it).
     * @param int $maxSeconds       Hard ceiling on wall-clock seconds spent collecting per sync.
     * @param int $heartbeatSeconds Minimum seconds between lock heartbeats written while collecting;
     *                              renewing the lock this often keeps a long but healthy sync from being
     *                              mistaken for a stale one (constructor-injectable so tests can force it).
     */
    public function __construct(
        private readonly GitHubClientInterface $gitHub,
        private readonly SkillMarkdownParser $parser,
        private readonly MarketplaceParser $marketplaceParser,
        private readonly SkillDiscovery $discovery,
        private readonly SkillRepository $skillRepository,
        private readonly SkillSourceRepository $sourceRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly LoggerInterface $logger,
        private readonly int $maxFiles = 500,
        private readonly int $maxSeconds = 120,
        private readonly int $heartbeatSeconds = 30,
        // Isolation collaborators (ADR-061), autowired in production. Optional
        // and trailing so the existing lean test constructions keep working;
        // when a collaborator is absent the corresponding control degrades to a
        // no-op (never a silent LOOSENING — trust denormalisation, which needs
        // no collaborator, always runs).
        private readonly ?PromptInjectionScanner $scanner = null,
        private readonly ?SkillManifestVerifier $manifestVerifier = null,
        private readonly ?SkillAuditService $audit = null,
    ) {}

    public function sync(SkillSource $source): SyncResult
    {
        $now = time();
        if ($this->isLockActive($source, $now)) {
            return new SyncResult(SyncStatus::SYNCING, errors: ['A sync is already running for this source.']);
        }

        // Acquire the lock and write a heartbeat BEFORE the work so a crash leaves a reclaimable lock.
        $source->setSyncStatus(SyncStatus::SYNCING->value);
        $source->setLastSynced($now);
        $this->persistSource($source);

        $this->lastHeartbeat = $now;
        $this->syncDeadline = $now + $this->maxSeconds;
        $this->filesProcessed = 0;
        $this->boundsExceeded = false;

        $errors = [];
        $seen = [];
        $created = 0;
        $updated = 0;
        $disabledOnChange = 0;
        $injectionBlocked = 0;
        $orphaned = 0;

        try {
            $collected = $this->collect($source, $errors);
            $sourcePrefix = $source->getUid() . ':';

            // Fail-closed manifest fingerprint gate (ADR-061): when the source
            // declares an expected fingerprint, the whole discovered set must
            // verify BEFORE any skill is materialized. A mismatch blocks the
            // ingest entirely — no upsert, no orphaning — leaving the last
            // known-good skills untouched, and is audited.
            if ($this->fingerprintRejected($source, $collected['parsed'], $sourcePrefix)) {
                $errors[] = 'Manifest fingerprint verification failed; ingest blocked (no skills were materialized).';
                $status = SyncStatus::ERROR;
                $orphaned = 0;
                $source->setSyncError($this->sanitizeErrorMessage(implode("\n", $errors)));
            } else {
                foreach ($collected['parsed'] as [$sha, $parsed]) {
                    $identifier = $sourcePrefix . $parsed->path;
                    // Keyed set: O(1) dedup instead of in_array() over a growing list.
                    if (isset($seen[$identifier])) {
                        $errors[] = sprintf('duplicate identifier "%s", first wins', $identifier);
                        continue;
                    }
                    $seen[$identifier] = true;
                    [$outcome, $blocked] = $this->upsert($source, $identifier, $sha, $parsed);
                    $created += $outcome === 'created' ? 1 : 0;
                    $updated += $outcome === 'updated' ? 1 : 0;
                    $disabledOnChange += $outcome === 'changed' ? 1 : 0;
                    $injectionBlocked += $blocked ? 1 : 0;
                }

                // Orphan by upstream PRESENCE (discovered identifiers), not by parse success.
                $discoveredIds = array_map(
                    static fn(string $path): string => $sourcePrefix . $path,
                    $collected['discovered'],
                );
                $orphaned = $this->orphanRemoved(
                    $source,
                    $discoveredIds,
                    $this->prefixIdentifiers($collected['reachedPrefixes'], $sourcePrefix),
                    $this->prefixIdentifiers($collected['listedPrefixes'], $sourcePrefix),
                );

                $status = $errors === [] ? SyncStatus::OK : SyncStatus::PARTIAL;
                // Only single_file/repo pin the source SHA; marketplace child-repo SHAs must not overwrite it.
                if ($collected['rootSha'] !== null) {
                    $source->setPinnedSha($collected['rootSha']);
                }
                $source->setSyncError($this->sanitizeErrorMessage(implode("\n", $errors)));
            }
        } catch (Throwable $e) {
            // Keep the full stack trace server-side (GitHub rate limits, network
            // timeouts, DBAL/persistence errors) — the user only sees getMessage().
            $this->logger->error('Skill sync failed', [
                'exception'  => $e,
                'source_uid' => $source->getUid(),
                'source_url' => $source->getUrl(),
            ]);
            $status = SyncStatus::ERROR;
            $orphaned = 0;
            $errors[] = $e->getMessage();
            $source->setSyncError($this->sanitizeErrorMessage($e->getMessage()));
        }

        // Always persist the final state so the lock is never left stuck.
        $source->setSyncStatus($status->value);
        $source->setLastSynced(time());
        $this->persistSource($source);

        return new SyncResult($status, $created, $updated, $disabledOnChange, $orphaned, $errors, $injectionBlocked);
    }

    /**
     * Fail-closed manifest fingerprint gate for a source that declares one.
     *
     * Returns true (ingest must be blocked) only when a fingerprint is declared
     * AND the recomputed manifest digest over the discovered
     * (identifier → body-checksum) set does not verify. An undeclared
     * fingerprint, an absent verifier (lean test wiring) or a successful verify
     * all return false (ingest proceeds). A rejection is recorded in the audit
     * trail before the sync is aborted.
     *
     * @param list<array{0:string,1:ParsedSkill}> $parsed
     */
    private function fingerprintRejected(SkillSource $source, array $parsed, string $sourcePrefix): bool
    {
        if ($this->manifestVerifier === null || !$this->manifestVerifier->isDeclared($source->getExpectedFingerprint())) {
            return false;
        }

        $manifest = [];
        foreach ($parsed as [, $parsedSkill]) {
            $manifest[$sourcePrefix . $parsedSkill->path] = hash('sha256', $parsedSkill->body);
        }

        if ($this->manifestVerifier->verify($source->getExpectedFingerprint(), $manifest)) {
            return false;
        }

        $this->audit?->recordSourceEvent(
            SkillAuditEvent::FINGERPRINT_REJECTED,
            $source,
            sprintf('computed %s', $this->manifestVerifier->computeFingerprint($manifest)),
        );

        return true;
    }

    /**
     * Concurrency guard with stale-lock recovery: a SYNCING source is only an
     * active lock when its heartbeat (lastSynced) is within STALE_LOCK_SECONDS
     * of now. An old or never-set (0) heartbeat is stale and reclaimable, so a
     * crashed sync does not wedge the source forever. The window is measured
     * with abs() so a heartbeat written under clock skew / a backwards NTP
     * correction (a future timestamp) cannot lock the source indefinitely — a
     * far-future timestamp is treated as stale, a small skew as still active.
     */
    private function isLockActive(SkillSource $source, int $now): bool
    {
        return $source->getSyncStatusEnum() === SyncStatus::SYNCING
            && $source->getLastSynced() !== 0
            && abs($now - $source->getLastSynced()) <= self::STALE_LOCK_SECONDS;
    }

    /**
     * Reclaim a source whose SYNCING lock is stale: the previous sync was interrupted (the process
     * was killed / timed out before {@see sync()} could release the lock), so the source would
     * otherwise display a perpetual "Syncing" badge and reject a retry until the window elapses on
     * the next manual attempt. Flip it to ERROR — but only when the lock is provably NOT active, so
     * a genuinely running sync (fresh heartbeat) and an already-terminal source are left untouched.
     * Intended to be called when listing sources, turning an invisible auto-reclaim into a visible,
     * retryable state. Returns true if it reclaimed the lock.
     */
    public function reclaimStaleLock(SkillSource $source): bool
    {
        if ($source->getSyncStatusEnum() !== SyncStatus::SYNCING || $this->isLockActive($source, time())) {
            return false;
        }
        $source->setSyncStatus(SyncStatus::ERROR->value);
        $source->setSyncError('The previous sync was interrupted before it finished. Trigger a new sync to retry.');
        $this->persistSource($source);
        return true;
    }

    /**
     * @param list<string> $errors
     *
     * @return array{
     *     parsed: list<array{0:string,1:ParsedSkill}>,
     *     discovered: list<string>,
     *     reachedPrefixes: ?list<string>,
     *     listedPrefixes: ?list<string>,
     *     rootSha: ?string,
     * }
     */
    private function collect(SkillSource $source, array &$errors): array
    {
        // Resolve the type once and fail closed on an unknown/invalid stored value:
        // a malformed type must surface as a clear ERROR, never be silently treated as a repo.
        $type = $source->getTypeEnum()
            ?? throw new RuntimeException(sprintf('Unknown skill source type "%s".', $source->getType()), 4636357234);

        if ($type === SkillSourceType::MARKETPLACE) {
            return $this->collectMarketplace($source, $errors);
        }
        // Non-marketplace sources address a single GitHub repo; an unparseable URL is fatal and
        // surfaces as a clear ERROR rather than a malformed API call against an empty owner/repo.
        [$owner, $repo] = $this->requireOwnerRepo($source->getUrl());
        return $type === SkillSourceType::SINGLE_FILE
            ? $this->collectSingleFile($source, $owner, $repo, $errors)
            : $this->collectRepo($source, $owner, $repo, $source->getRef(), $errors);
    }

    /**
     * @param list<string> $errors
     *
     * @return array{parsed: list<array{0:string,1:ParsedSkill}>, discovered: list<string>, reachedPrefixes: ?list<string>, listedPrefixes: ?list<string>, rootSha: ?string}
     */
    private function collectSingleFile(SkillSource $source, string $owner, string $repo, array &$errors): array
    {
        $path = $this->pathFromUrl($source->getUrl());
        // resolveSha is the source-root reach; a failure here is fatal (caught by sync()).
        $sha = $this->gitHub->resolveSha($owner, $repo, $this->refOrHead($source->getRef()), $this->token($source));

        // The single file is "discovered" (present) unless the resolved commit returns a hard 404 for it.
        $parsed = [];
        $discovered = [$path];
        try {
            $body = $this->gitHub->fetchRawBySha($owner, $repo, $sha, $path, $this->token($source));
            $parsed[] = [$sha, $this->parser->parse($path, $body)];
        } catch (GitHubApiException $e) {
            if ($e->isRateLimit) {
                throw $e;
            }
            if ($e->status === 404) {
                // The file is gone at the resolved commit: drop it from discovered so it is orphaned.
                // A non-404 (transient) error leaves it discovered (present) and does not orphan it.
                $discovered = [];
            }
            $errors[] = $e->getMessage();
        } catch (SkillParseException $e) {
            $errors[] = $e->getMessage();
        }

        return ['parsed' => $parsed, 'discovered' => $discovered, 'reachedPrefixes' => null, 'listedPrefixes' => null, 'rootSha' => $sha];
    }

    /**
     * @param list<string> $errors
     *
     * @return array{parsed: list<array{0:string,1:ParsedSkill}>, discovered: list<string>, reachedPrefixes: ?list<string>, listedPrefixes: ?list<string>, rootSha: ?string}
     */
    private function collectRepo(SkillSource $source, string $owner, string $repo, string $ref, array &$errors): array
    {
        // resolveSha / listTree are the source-root reach; failures here are fatal for this repo
        // (caught by sync() for repo sources, or by collectMarketplace() per-repo).
        $sha = $this->gitHub->resolveSha($owner, $repo, $this->refOrHead($ref), $this->token($source));
        $paths = $this->discovery->discover($this->gitHub->listTree($owner, $repo, $sha, $this->token($source)));

        // Discovered = every path the tree listing surfaced, even if its body fetch/parse fails below.
        $parsed = [];
        foreach ($paths as $path) {
            if ($this->limitReached($source, $errors)) {
                break;
            }
            $this->filesProcessed++;
            try {
                $body = $this->gitHub->fetchRawBySha($owner, $repo, $sha, $path, $this->token($source));
                $parsed[] = [$sha, $this->parser->parse($path, $body)];
            } catch (GitHubApiException $e) {
                if ($e->isRateLimit) {
                    throw $e;
                }
                $errors[] = $e->getMessage();
            } catch (SkillParseException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return ['parsed' => $parsed, 'discovered' => $paths, 'reachedPrefixes' => null, 'listedPrefixes' => null, 'rootSha' => $sha];
    }

    /**
     * @param list<string> $errors
     *
     * @return array{parsed: list<array{0:string,1:ParsedSkill}>, discovered: list<string>, reachedPrefixes: ?list<string>, listedPrefixes: ?list<string>, rootSha: ?string}
     */
    private function collectMarketplace(SkillSource $source, array &$errors): array
    {
        // The marketplace index fetch + parse are the source-root reach; failures here are fatal.
        $index = $this->fetchMarketplaceIndex($source);

        $parsed = [];
        $discovered = [];
        $reachedPrefixes = [];
        $listedPrefixes = [];
        // Keyed set mirroring $listedPrefixes: O(1) duplicate detection instead
        // of in_array() over the growing list (the $listedPrefixes list is kept
        // because it is part of this method's return contract).
        $listedPrefixSet = [];
        foreach ($this->marketplaceParser->parse($index) as $entry) {
            // Namespace marketplace skills by repo to avoid path collisions across plugins.
            $prefix = $entry->owner . '/' . $entry->repo . '/';
            // Dedup duplicate plugin entries (same owner/repo): first wins.
            if (isset($listedPrefixSet[$prefix])) {
                $errors[] = sprintf('duplicate marketplace plugin "%s/%s", first wins', $entry->owner, $entry->repo);
                continue;
            }
            // "Listed" = present in the parsed index this run, even if unreached below. A skill whose
            // prefix is no longer listed (plugin de-listed) IS orphaned; one listed-but-unreached is not.
            $listedPrefixes[]          = $prefix;
            $listedPrefixSet[$prefix]  = true;

            // A bound hit leaves the remaining plugins listed (protected) but unvisited this run.
            if ($this->limitReached($source, $errors)) {
                continue;
            }
            try {
                $repoResult = $this->collectRepo($source, $entry->owner, $entry->repo, $entry->ref ?? 'HEAD', $errors);
            } catch (GitHubApiException $e) {
                if ($e->isRateLimit) {
                    throw $e;
                }
                // An unreachable child repo is recorded and skipped (PARTIAL); it stays "listed" (so its
                // existing skills are protected as a transient failure) but is excluded from "reached".
                $errors[] = $e->getMessage();
                continue;
            } catch (HostNotAllowedException $e) {
                $errors[] = $e->getMessage();
                continue;
            }

            $reachedPrefixes[] = $prefix;
            foreach ($repoResult['discovered'] as $path) {
                $discovered[] = $prefix . $path;
            }
            foreach ($repoResult['parsed'] as $row) {
                $parsed[] = [$row[0], new ParsedSkill(
                    $prefix . $row[1]->path,
                    $row[1]->name,
                    $row[1]->description,
                    $row[1]->body,
                    $row[1]->rawFrontmatter,
                    $row[1]->supportStatus,
                    $row[1]->unsupportedNotes,
                )];
            }
        }

        return ['parsed' => $parsed, 'discovered' => $discovered, 'reachedPrefixes' => $reachedPrefixes, 'listedPrefixes' => $listedPrefixes, 'rootSha' => null];
    }

    /**
     * Stop collecting once the per-sync file or wall-time bound is exceeded. Called once per unit of
     * work (per file / per plugin), so it is also where the lock heartbeat is renewed.
     *
     * @param list<string> $errors
     */
    private function limitReached(SkillSource $source, array &$errors): bool
    {
        $this->heartbeat($source);
        if ($this->boundsExceeded) {
            return true;
        }
        if ($this->filesProcessed >= $this->maxFiles || time() >= $this->syncDeadline) {
            $this->boundsExceeded = true;
            $errors[] = sprintf(
                'Per-sync limit reached (max %d files / %d seconds); collection stopped early.',
                $this->maxFiles,
                $this->maxSeconds,
            );
            return true;
        }
        return false;
    }

    /**
     * Renew the SYNCING lock by re-stamping the heartbeat (lastSynced), throttled to at most once
     * per {@see $heartbeatSeconds}. This keeps a long but healthy sync from ageing past
     * STALE_LOCK_SECONDS and being reclaimed as stale, while a crashed sync stops renewing and
     * becomes reclaimable within one window. Only the source row is flushed here (skills are
     * upserted after collection), so the mid-collect persist cannot leak partial skill state.
     */
    private function heartbeat(SkillSource $source): void
    {
        $now = time();
        if ($now - $this->lastHeartbeat < $this->heartbeatSeconds) {
            return;
        }
        $this->lastHeartbeat = $now;
        $source->setLastSynced($now);
        $this->persistSource($source);
    }

    /**
     * Upsert one parsed skill, applying the ADR-061 isolation controls
     * (trust denormalisation, injection scan + fail-closed force-disable) and
     * recording the outcome in the audit trail.
     *
     * @return array{0:string,1:bool} [outcome, forcedDisable] where outcome is
     *                                'created' | 'updated' | 'changed' (an enabled skill auto-disabled on
     *                                body change) and forcedDisable is true when a high-confidence injection
     *                                finding disabled a skill that would otherwise have been enabled.
     */
    private function upsert(SkillSource $source, string $identifier, string $sha, ParsedSkill $parsed): array
    {
        $checksum = hash('sha256', $parsed->body);
        $scan     = $this->scanner?->scan($parsed->body);
        $highConf = $scan?->hasHighConfidence() ?? false;
        $existing = $this->skillRepository->findBySourceAndIdentifier($source->getUid() ?? 0, $identifier);

        if ($existing === null) {
            $skill = new Skill();
            $skill->setSource($source->getUid() ?? 0);
            $skill->setIdentifier($identifier);
            $this->apply($skill, $parsed, $sha, $checksum);
            $this->applyIsolationMetadata($skill, $source, $scan);
            // single_file defaults enabled; a high-confidence injection finding
            // force-disables it (fail-closed) regardless of source type.
            $wouldEnable   = $source->getTypeEnum() === SkillSourceType::SINGLE_FILE;
            $forcedDisable = $highConf && $wouldEnable;
            $skill->setEnabled($wouldEnable && !$highConf);
            $skill->setOrphaned(false);
            $this->skillRepository->add($skill);
            $this->audit?->recordSkillEvent(SkillAuditEvent::INGEST_CREATED, $skill);
            if ($forcedDisable) {
                $this->audit?->recordSkillEvent(SkillAuditEvent::INJECTION_BLOCKED, $skill, $this->highConfidenceLabels($scan));
            }

            return ['created', $forcedDisable];
        }

        $changed    = $existing->getBodyChecksum() !== $checksum;
        $wasEnabled = $existing->isEnabled();
        $this->apply($existing, $parsed, $sha, $checksum);
        $this->applyIsolationMetadata($existing, $source, $scan);
        $existing->setOrphaned(false);
        $outcome       = 'updated';
        $forcedDisable = $highConf && $wasEnabled;
        // Auto-disable an enabled skill on a body change (ADR-035) OR on a
        // high-confidence injection finding (ADR-061) — both are fail-closed
        // re-reviews. 'changed' is reserved for the body-change reason.
        if ($wasEnabled && ($changed || $highConf)) {
            $existing->setEnabled(false);
            $outcome = $changed ? 'changed' : 'updated';
        }
        $this->skillRepository->update($existing);
        $this->audit?->recordSkillEvent(
            $outcome === 'changed' ? SkillAuditEvent::INGEST_DISABLED_ON_CHANGE : SkillAuditEvent::INGEST_UPDATED,
            $existing,
        );
        if ($forcedDisable) {
            $this->audit?->recordSkillEvent(SkillAuditEvent::INJECTION_BLOCKED, $existing, $this->highConfidenceLabels($scan));
        }

        return [$outcome, $forcedDisable];
    }

    /**
     * Denormalise the source's trust level onto the skill and store the
     * injection-scan findings (mirrors how support_status flows from parse to
     * skill). Trust is copied every sync so a source re-classification takes
     * effect on the next sync; the scan JSON is only overwritten when a scanner
     * is wired.
     */
    private function applyIsolationMetadata(Skill $skill, SkillSource $source, ?InjectionScanResult $scan): void
    {
        $skill->setTrustLevel($source->getTrustLevel());
        if ($scan !== null) {
            $skill->setInjectionScan((string)json_encode($scan->toArray()));
        }
    }

    /**
     * Comma-joined HIGH-severity finding labels for the audit detail.
     */
    private function highConfidenceLabels(?InjectionScanResult $scan): string
    {
        if ($scan === null) {
            return '';
        }
        $labels = [];
        foreach ($scan->findings as $finding) {
            if ($finding->severity === InjectionSeverity::HIGH) {
                $labels[] = $finding->label;
            }
        }

        return $labels === [] ? '' : 'high-confidence: ' . implode(', ', array_values(array_unique($labels)));
    }

    private function apply(Skill $skill, ParsedSkill $parsed, string $sha, string $checksum): void
    {
        $skill->setName($parsed->name);
        $skill->setDescription($parsed->description);
        $skill->setBody($parsed->body);
        $skill->setBodyChecksum($checksum);
        $skill->setSourceSha($sha);
        $skill->setRawFrontmatter((string)json_encode($parsed->rawFrontmatter));
        $skill->setSupportStatus($parsed->supportStatus->value);
        $skill->setUnsupportedNotes($parsed->unsupportedNotes);
        // Distinguish "no opinion" (key absent → store '') from a present declaration (store its JSON,
        // including '[]' for a declared-empty fail-closed list). The accessor treats '' as null/no-opinion.
        $frontmatter = $parsed->rawFrontmatter;
        if (!array_key_exists('allowed-tools', $frontmatter) && !array_key_exists('allowed_tools', $frontmatter)) {
            $skill->setAllowedTools('');
        } else {
            $tools = $frontmatter['allowed-tools'] ?? $frontmatter['allowed_tools'] ?? [];
            // A string form ("GetTca, GetEnv" / "GetTca GetEnv" / a single name)
            // is a real declaration, not "no tools": split it into a list rather
            // than collapsing to the declared-empty (fail-closed, all-tools-off)
            // list. Only a genuinely empty/whitespace value stays empty.
            if (is_string($tools)) {
                $tools = preg_split('/[\s,]+/', trim($tools), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $skill->setAllowedTools((string)json_encode(is_array($tools) ? array_values($tools) : []));
        }
    }

    /**
     * Prefix every entry with the per-source identifier prefix, preserving the
     * null passthrough (null means "not a marketplace source").
     *
     * @param list<string>|null $prefixes
     *
     * @return list<string>|null
     */
    private function prefixIdentifiers(?array $prefixes, string $sourcePrefix): ?array
    {
        if ($prefixes === null) {
            return null;
        }
        return array_map(static fn(string $prefix): string => $sourcePrefix . $prefix, $prefixes);
    }

    /**
     * Orphan DB skills that are absent from the discovered (upstream-present) set.
     *
     * @param list<string>  $discovered      Full identifiers present upstream this run.
     * @param ?list<string> $reachedPrefixes Marketplace only (null otherwise): prefixes whose child repo
     *                                       was successfully visited this run.
     * @param ?list<string> $listedPrefixes  Marketplace only (null otherwise): prefixes present in the
     *                                       parsed index this run (whether or not the child repo was reached).
     */
    private function orphanRemoved(SkillSource $source, array $discovered, ?array $reachedPrefixes, ?array $listedPrefixes): int
    {
        // O(1) membership lookup instead of in_array() over the (unbounded)
        // discovered list for every DB skill. Identifiers are unique strings,
        // so array_flip is a faithful hash set.
        $discoveredSet = array_flip($discovered);
        $count = 0;
        foreach ($this->skillRepository->findBySource($source->getUid() ?? 0) as $skill) {
            $identifier = $skill->getIdentifier();
            if (isset($discoveredSet[$identifier])) {
                continue;
            }
            if (
                $reachedPrefixes !== null
                && $listedPrefixes !== null
                && !$this->orphanEligible($identifier, $reachedPrefixes, $listedPrefixes)
            ) {
                continue;
            }
            if (!$skill->isOrphaned()) {
                $skill->setOrphaned(true);
                $skill->setEnabled(false);
                $this->skillRepository->update($skill);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Decide whether an absent marketplace skill may be orphaned.
     *
     * Orphan when its child repo WAS reached (the skill was genuinely removed there) OR when its prefix
     * is no longer listed in the index (the plugin was de-listed). Protect (do not orphan) only the
     * listed-but-unreached case — a transient child-repo failure this run.
     *
     * @param list<string> $reachedPrefixes
     * @param list<string> $listedPrefixes
     */
    private function orphanEligible(string $identifier, array $reachedPrefixes, array $listedPrefixes): bool
    {
        return $this->inScope($identifier, $reachedPrefixes)
            || !$this->inScope($identifier, $listedPrefixes);
    }

    /**
     * @param list<string> $prefixes
     */
    private function inScope(string $identifier, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($identifier, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function persistSource(SkillSource $source): void
    {
        if ($source->getUid() === null) {
            $this->sourceRepository->add($source);
        } else {
            try {
                $this->sourceRepository->update($source);
            } catch (UnknownObjectException) {
                // A source that carries a uid but is not part of the current
                // persistence session (e.g. constructed detached) cannot be
                // updated; its bookkeeping row is managed elsewhere. Skip the
                // update but still flush any pending skill changes below.
            }
        }
        $this->persistenceManager->persistAll();
    }

    private function token(SkillSource $source): ?string
    {
        $token = $source->getGithubToken();
        return $token === '' ? null : $token;
    }

    private function refOrHead(string $ref): string
    {
        return $ref === '' ? 'HEAD' : $ref;
    }

    /**
     * Fetch the marketplace index JSON for a marketplace source.
     *
     * Accepts every form an admin is likely to paste:
     * - a raw index URL (``raw.githubusercontent.com/…``) is fetched verbatim;
     * - a github.com *blob* view URL (``…/blob/<ref>/<path>``, copied from the
     *   browser) is converted to its raw equivalent and fetched — the HTML view
     *   would otherwise fail to parse as JSON;
     * - a plain repository URL (``https://github.com/owner/repo``) is resolved
     *   to the Claude Code convention path {@see self::MARKETPLACE_INDEX_PATH}
     *   on the repository's default branch.
     *
     * @throws RuntimeException When the URL is neither a raw/blob index nor a GitHub repository URL.
     */
    private function fetchMarketplaceIndex(SkillSource $source): string
    {
        $url   = $source->getUrl();
        $token = $this->token($source);
        $host  = parse_url($url, PHP_URL_HOST);
        $host  = is_string($host) ? strtolower($host) : '';

        // Already a raw file URL → fetch verbatim (it is expected to BE the index).
        if ($host === 'raw.githubusercontent.com') {
            return $this->gitHub->fetchAllowedUrl($url, $token);
        }

        // A github.com "blob" view URL (e.g. copied from the browser address bar:
        // …/blob/<ref>/<path>) is NOT raw — fetching it returns HTML. Convert it
        // to its raw equivalent and fetch that exact path.
        if (($host === 'github.com' || $host === 'www.github.com') && str_contains($url, '/blob/')) {
            $raw = preg_replace(
                '#^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/blob/#',
                'https://raw.githubusercontent.com/$1/$2/',
                $url,
            );
            if (is_string($raw) && $raw !== $url) {
                return $this->gitHub->fetchAllowedUrl($raw, $token);
            }
        }

        // Otherwise treat it as a plain GitHub repository URL and resolve the
        // convention index on the default branch.
        [$owner, $repo] = $this->ownerRepo($url);
        if ($owner === '' || $repo === '') {
            throw new RuntimeException(
                sprintf(
                    'Marketplace URL "%s" is neither a raw marketplace.json nor a GitHub repository URL. '
                    . 'Use the repository URL (https://github.com/<owner>/<repo>) or the raw index URL '
                    . '(https://raw.githubusercontent.com/<owner>/<repo>/<branch>/.claude-plugin/marketplace.json).',
                    $url,
                ),
                1751280001,
            );
        }

        // Resolve the default-branch HEAD, then fetch the convention index path.
        $sha = $this->gitHub->resolveSha($owner, $repo, 'HEAD', $token);

        return $this->gitHub->fetchRawBySha($owner, $repo, $sha, self::MARKETPLACE_INDEX_PATH, $token);
    }

    /**
     * @throws RuntimeException When the URL is not a recognisable GitHub owner/repo URL.
     *
     * @return array{0:string,1:string}
     */
    private function requireOwnerRepo(string $url): array
    {
        [$owner, $repo] = $this->ownerRepo($url);
        if ($owner === '' || $repo === '') {
            throw new RuntimeException(sprintf('Not a GitHub URL: %s', $url), 1719500201);
        }
        return [$owner, $repo];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function ownerRepo(string $url): array
    {
        $url = $this->stripQueryAndFragment($url);
        if (preg_match('#github(?:usercontent)?\.com/([^/]+)/([^/]+)#', $url, $m) === 1) {
            return [$m[1], preg_replace('/\.git$/', '', $m[2]) ?? $m[2]];
        }
        return ['', ''];
    }

    private function pathFromUrl(string $url): string
    {
        $url = $this->stripQueryAndFragment($url);
        // raw URL: https://raw.githubusercontent.com/owner/repo/ref/<path>
        if (preg_match('#raw\.githubusercontent\.com/[^/]+/[^/]+/[^/]+/(.+)$#', $url, $m) === 1) {
            return $m[1];
        }
        // blob URL: https://github.com/owner/repo/blob/ref/<path>
        if (preg_match('#github\.com/[^/]+/[^/]+/blob/[^/]+/(.+)$#', $url, $m) === 1) {
            return $m[1];
        }
        return 'SKILL.md';
    }

    /**
     * Drop any URL query string and/or fragment before matching, so e.g.
     * ".../SKILL.md?token=..#frag" parses to the bare path/owner/repo.
     */
    private function stripQueryAndFragment(string $url): string
    {
        return substr($url, 0, strcspn($url, '?#'));
    }
}
