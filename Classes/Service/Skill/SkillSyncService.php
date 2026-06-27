<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Domain\ValueObject\ParsedSkill;
use Netresearch\NrLlm\Domain\ValueObject\SyncResult;
use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\Exception\HostNotAllowedException;
use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class SkillSyncService
{
    /** A SYNCING lock older than this many seconds is considered stale and may be reclaimed. */
    private const STALE_LOCK_SECONDS = 600;

    private int $syncDeadline = 0;
    private int $filesProcessed = 0;
    private bool $boundsExceeded = false;

    /**
     * @param int $maxFiles   Hard ceiling on files fetched per sync; prevents a huge repo/marketplace
     *                        from running unbounded (constructor-injectable so tests can lower it).
     * @param int $maxSeconds Hard ceiling on wall-clock seconds spent collecting per sync.
     */
    public function __construct(
        private readonly GitHubClientInterface $gitHub,
        private readonly SkillMarkdownParser $parser,
        private readonly MarketplaceParser $marketplaceParser,
        private readonly SkillDiscovery $discovery,
        private readonly SkillRepository $skillRepository,
        private readonly SkillSourceRepository $sourceRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly int $maxFiles = 500,
        private readonly int $maxSeconds = 120,
    ) {}

    public function sync(SkillSource $source): SyncResult
    {
        $now = time();
        // Concurrency guard with stale-lock recovery: a SYNCING source is only treated as locked
        // when its heartbeat (lastSynced) is recent; an old or never-set heartbeat is stale.
        if (
            $source->getSyncStatus() === SyncStatus::SYNCING
            && $source->getLastSynced() !== 0
            && ($now - $source->getLastSynced()) <= self::STALE_LOCK_SECONDS
        ) {
            return new SyncResult(SyncStatus::SYNCING, errors: ['A sync is already running for this source.']);
        }

        // Acquire the lock and write a heartbeat BEFORE the work so a crash leaves a reclaimable lock.
        $source->setSyncStatus(SyncStatus::SYNCING);
        $source->setLastSynced($now);
        $this->persistSource($source);

        $this->syncDeadline = $now + $this->maxSeconds;
        $this->filesProcessed = 0;
        $this->boundsExceeded = false;

        $errors = [];
        $seen = [];
        $created = 0;
        $updated = 0;
        $disabledOnChange = 0;
        $orphaned = 0;

        try {
            $collected = $this->collect($source, $errors);
            $sourcePrefix = $source->getUid() . ':';

            foreach ($collected['parsed'] as [$sha, $parsed]) {
                $identifier = $sourcePrefix . $parsed->path;
                if (in_array($identifier, $seen, true)) {
                    $errors[] = sprintf('duplicate identifier "%s", first wins', $identifier);
                    continue;
                }
                $seen[] = $identifier;
                $outcome = $this->upsert($source, $identifier, $sha, $parsed);
                $created += $outcome === 'created' ? 1 : 0;
                $updated += $outcome === 'updated' ? 1 : 0;
                $disabledOnChange += $outcome === 'changed' ? 1 : 0;
            }

            // Orphan by upstream PRESENCE (discovered identifiers), not by parse success.
            $discoveredIds = array_map(
                static fn(string $path): string => $sourcePrefix . $path,
                $collected['discovered'],
            );
            $prefixIds = static fn(?array $prefixes): ?array => $prefixes === null
                ? null
                : array_map(static fn(string $prefix): string => $sourcePrefix . $prefix, $prefixes);
            $orphaned = $this->orphanRemoved(
                $source,
                $discoveredIds,
                $prefixIds($collected['reachedPrefixes']),
                $prefixIds($collected['listedPrefixes']),
            );

            $status = $errors === [] ? SyncStatus::OK : SyncStatus::PARTIAL;
            // Only single_file/repo pin the source SHA; marketplace child-repo SHAs must not overwrite it.
            if ($collected['rootSha'] !== null) {
                $source->setPinnedSha($collected['rootSha']);
            }
            $source->setSyncError(implode("\n", $errors));
        } catch (Throwable $e) {
            $status = SyncStatus::ERROR;
            $orphaned = 0;
            $errors[] = $e->getMessage();
            $source->setSyncError($e->getMessage());
        }

        // Always persist the final state so the lock is never left stuck.
        $source->setSyncStatus($status);
        $source->setLastSynced(time());
        $this->persistSource($source);

        return new SyncResult($status, $created, $updated, $disabledOnChange, $orphaned, $errors);
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
        if ($source->getType() === SkillSourceType::MARKETPLACE) {
            return $this->collectMarketplace($source, $errors);
        }
        // Non-marketplace sources address a single GitHub repo; an unparseable URL is fatal and
        // surfaces as a clear ERROR rather than a malformed API call against an empty owner/repo.
        [$owner, $repo] = $this->requireOwnerRepo($source->getUrl());
        return $source->getType() === SkillSourceType::SINGLE_FILE
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
            if ($this->limitReached($errors)) {
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
        $index = $this->gitHub->fetchAllowedUrl($source->getUrl(), $this->token($source));

        $parsed = [];
        $discovered = [];
        $reachedPrefixes = [];
        $listedPrefixes = [];
        foreach ($this->marketplaceParser->parse($index) as $entry) {
            // Namespace marketplace skills by repo to avoid path collisions across plugins.
            $prefix = $entry->owner . '/' . $entry->repo . '/';
            // Dedup duplicate plugin entries (same owner/repo): first wins.
            if (in_array($prefix, $listedPrefixes, true)) {
                $errors[] = sprintf('duplicate marketplace plugin "%s/%s", first wins', $entry->owner, $entry->repo);
                continue;
            }
            // "Listed" = present in the parsed index this run, even if unreached below. A skill whose
            // prefix is no longer listed (plugin de-listed) IS orphaned; one listed-but-unreached is not.
            $listedPrefixes[] = $prefix;

            // A bound hit leaves the remaining plugins listed (protected) but unvisited this run.
            if ($this->limitReached($errors)) {
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
     * Stop collecting once the per-sync file or wall-time bound is exceeded.
     *
     * @param list<string> $errors
     */
    private function limitReached(array &$errors): bool
    {
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
     * Returns 'created' | 'updated' | 'changed' (changed = enabled skill auto-disabled on body change).
     */
    private function upsert(SkillSource $source, string $identifier, string $sha, ParsedSkill $parsed): string
    {
        $checksum = hash('sha256', $parsed->body);
        $existing = $this->skillRepository->findBySourceAndIdentifier($source->getUid(), $identifier);

        if ($existing === null) {
            $skill = new Skill();
            $skill->setSource($source->getUid());
            $skill->setIdentifier($identifier);
            $this->apply($skill, $parsed, $sha, $checksum);
            $skill->setEnabled($source->getType() === SkillSourceType::SINGLE_FILE);
            $skill->setOrphaned(false);
            $this->skillRepository->add($skill);
            return 'created';
        }

        $changed = $existing->getBodyChecksum() !== $checksum;
        $wasEnabled = $existing->isEnabled();
        $this->apply($existing, $parsed, $sha, $checksum);
        $existing->setOrphaned(false);
        $outcome = 'updated';
        if ($changed && $wasEnabled) {
            $existing->setEnabled(false);
            $outcome = 'changed';
        }
        $this->skillRepository->update($existing);
        return $outcome;
    }

    private function apply(Skill $skill, ParsedSkill $parsed, string $sha, string $checksum): void
    {
        $skill->setName($parsed->name);
        $skill->setDescription($parsed->description);
        $skill->setBody($parsed->body);
        $skill->setBodyChecksum($checksum);
        $skill->setSourceSha($sha);
        $skill->setRawFrontmatter((string)json_encode($parsed->rawFrontmatter));
        $skill->setSupportStatus($parsed->supportStatus);
        $skill->setUnsupportedNotes($parsed->unsupportedNotes);
        $tools = $parsed->rawFrontmatter['allowed-tools'] ?? $parsed->rawFrontmatter['allowed_tools'] ?? [];
        $skill->setAllowedTools((string)json_encode(is_array($tools) ? $tools : []));
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
        $count = 0;
        foreach ($this->skillRepository->findBySource($source->getUid()) as $skill) {
            $identifier = $skill->getIdentifier();
            if (in_array($identifier, $discovered, true)) {
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
