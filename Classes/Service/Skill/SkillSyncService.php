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
use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use Throwable;
use TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class SkillSyncService
{
    public function __construct(
        private readonly GitHubClientInterface $gitHub,
        private readonly SkillMarkdownParser $parser,
        private readonly MarketplaceParser $marketplaceParser,
        private readonly SkillDiscovery $discovery,
        private readonly SkillRepository $skillRepository,
        private readonly SkillSourceRepository $sourceRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
    ) {}

    public function sync(SkillSource $source): SyncResult
    {
        if ($source->getSyncStatus() === SyncStatus::SYNCING) {
            return new SyncResult(SyncStatus::SYNCING, errors: ['A sync is already running for this source.']);
        }
        $source->setSyncStatus(SyncStatus::SYNCING);
        $this->persistSource($source);

        $errors = [];
        $seen = [];
        $created = 0;
        $updated = 0;
        $disabledOnChange = 0;

        try {
            $parsedList = $this->collect($source, $errors);
            foreach ($parsedList as [$sha, $parsed]) {
                $identifier = $source->getUid() . ':' . $parsed->path;
                $seen[] = $identifier;
                $outcome = $this->upsert($source, $identifier, $sha, $parsed);
                $created += $outcome === 'created' ? 1 : 0;
                $updated += $outcome === 'updated' ? 1 : 0;
                $disabledOnChange += $outcome === 'changed' ? 1 : 0;
            }
            $orphaned = $this->orphanRemoved($source, $seen);
            $status = $errors === [] ? SyncStatus::OK : SyncStatus::PARTIAL;
            $source->setPinnedSha($parsedList[0][0] ?? $source->getPinnedSha());
            $source->setSyncError(implode("\n", $errors));
        } catch (Throwable $e) {
            $status = SyncStatus::ERROR;
            $orphaned = 0;
            $errors[] = $e->getMessage();
            $source->setSyncError($e->getMessage());
        }

        $source->setSyncStatus($status);
        $source->setLastSynced(time());
        $this->persistSource($source);

        return new SyncResult($status, $created, $updated, $disabledOnChange, $orphaned, $errors);
    }

    /**
     * @param list<string> $errors
     *
     * @return list<array{0:string,1:ParsedSkill}>
     */
    private function collect(SkillSource $source, array &$errors): array
    {
        [$owner, $repo] = $this->ownerRepo($source->getUrl());
        return match ($source->getType()) {
            SkillSourceType::SINGLE_FILE => $this->collectSingleFile($source, $owner, $repo, $errors),
            SkillSourceType::REPO => $this->collectRepo($source, $owner, $repo, $source->getRef(), $errors),
            SkillSourceType::MARKETPLACE => $this->collectMarketplace($source, $errors),
        };
    }

    /**
     * @param list<string> $errors
     *
     * @return list<array{0:string,1:ParsedSkill}>
     */
    private function collectSingleFile(SkillSource $source, string $owner, string $repo, array &$errors): array
    {
        $path = $this->pathFromUrl($source->getUrl());
        $sha = $this->gitHub->resolveSha($owner, $repo, $source->getRef(), $this->token($source));
        $body = $this->gitHub->fetchRawBySha($owner, $repo, $sha, $path, $this->token($source));
        try {
            return [[$sha, $this->parser->parse($path, $body)]];
        } catch (SkillParseException $e) {
            $errors[] = $e->getMessage();
            return [];
        }
    }

    /**
     * @param list<string> $errors
     *
     * @return list<array{0:string,1:ParsedSkill}>
     */
    private function collectRepo(SkillSource $source, string $owner, string $repo, string $ref, array &$errors): array
    {
        $sha = $this->gitHub->resolveSha($owner, $repo, $ref, $this->token($source));
        $paths = $this->discovery->discover($this->gitHub->listTree($owner, $repo, $sha, $this->token($source)));
        $out = [];
        foreach ($paths as $path) {
            $body = $this->gitHub->fetchRawBySha($owner, $repo, $sha, $path, $this->token($source));
            try {
                $out[] = [$sha, $this->parser->parse($path, $body)];
            } catch (SkillParseException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<array{0:string,1:ParsedSkill}>
     */
    private function collectMarketplace(SkillSource $source, array &$errors): array
    {
        $index = $this->gitHub->fetchAllowedUrl($source->getUrl(), $this->token($source));
        $out = [];
        foreach ($this->marketplaceParser->parse($index) as $entry) {
            foreach ($this->collectRepo($source, $entry->owner, $entry->repo, $entry->ref ?? 'HEAD', $errors) as $row) {
                // Namespace marketplace skills by repo to avoid path collisions across plugins.
                $out[] = [$row[0], new ParsedSkill(
                    $entry->owner . '/' . $entry->repo . '/' . $row[1]->path,
                    $row[1]->name,
                    $row[1]->description,
                    $row[1]->body,
                    $row[1]->rawFrontmatter,
                    $row[1]->supportStatus,
                    $row[1]->unsupportedNotes,
                )];
            }
        }
        return $out;
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
     * @param list<string> $seen
     */
    private function orphanRemoved(SkillSource $source, array $seen): int
    {
        $count = 0;
        foreach ($this->skillRepository->findBySource($source->getUid()) as $skill) {
            if (!in_array($skill->getIdentifier(), $seen, true) && !$skill->isOrphaned()) {
                $skill->setOrphaned(true);
                $skill->setEnabled(false);
                $this->skillRepository->update($skill);
                $count++;
            }
        }
        return $count;
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

    /**
     * @return array{0:string,1:string}
     */
    private function ownerRepo(string $url): array
    {
        if (preg_match('#github(?:usercontent)?\.com/([^/]+)/([^/]+)#', $url, $m) === 1) {
            return [$m[1], preg_replace('/\.git$/', '', $m[2]) ?? $m[2]];
        }
        return ['', ''];
    }

    private function pathFromUrl(string $url): string
    {
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
}
