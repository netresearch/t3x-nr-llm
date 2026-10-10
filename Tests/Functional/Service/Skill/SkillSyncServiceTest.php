<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\GitHubClientInterface;
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use Netresearch\NrLlm\Service\Skill\SkillDiscovery;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillSyncLeaseRepository;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Skill\Fixtures\FakeGitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

#[CoversClass(SkillSyncService::class)]
final class SkillSyncServiceTest extends AbstractFunctionalTestCase
{
    private const REPO_URL = 'https://github.com/acme/skills';

    private const SINGLE_FILE_URL = 'https://github.com/acme/skills/blob/main/SKILL.md';

    private const MARKET_URL = 'https://raw.githubusercontent.com/acme/market/main/marketplace.json';

    private const SKILL_A_PATH = 'skills/a/SKILL.md';

    private const SKILL_B_PATH = 'skills/b/SKILL.md';

    private const SKILL_A_ID = '10:skills/a/SKILL.md';

    private const SKILL_B_ID = '10:skills/b/SKILL.md';

    private const PLUGIN_A = 'p1/repoa';

    private const PLUGIN_B = 'p2/repob';

    private const MARKET_A_ID = '30:' . self::PLUGIN_A . '/' . self::SKILL_A_PATH;

    private const MARKET_B_ID = '30:' . self::PLUGIN_B . '/' . self::SKILL_A_PATH;

    private function service(
        GitHubClientInterface $gitHub,
        int $maxFiles = 500,
        int $maxSeconds = 120,
        int $heartbeatSeconds = 30,
        ?PersistenceManagerInterface $persistence = null,
    ): SkillSyncService {
        return new SkillSyncService(
            $gitHub,
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->get(SkillRepository::class),
            $persistence ?? $this->get(PersistenceManagerInterface::class),
            new NullLogger(),
            new SkillSyncLeaseRepository(
                $this->getConnectionPool(),
            ),
            $maxFiles,
            $maxSeconds,
            $heartbeatSeconds,
        );
    }

    #[Test]
    public function unknownStoredTypeYieldsErrorStatus(): void
    {
        $source = $this->persistedSource(40, 'bogus-type', self::REPO_URL);
        $result = $this->service($this->marketGitHub([]))->sync($source);
        self::assertSame(SyncStatus::ERROR, $result->status);
        self::assertStringContainsString(
            'Unknown skill source type',
            implode("\n", $result->errors),
        );
    }

    private function repoSource(int $uid = 10): SkillSource
    {
        return $this->persistedSource(
            $uid,
            SkillSourceType::REPO->value,
            self::REPO_URL,
            'main',
        );
    }

    private function singleFileSource(int $uid = 20): SkillSource
    {
        return $this->persistedSource(
            $uid,
            SkillSourceType::SINGLE_FILE->value,
            self::SINGLE_FILE_URL,
            'main',
        );
    }

    private function marketplaceSource(int $uid = 30): SkillSource
    {
        return $this->persistedSource(
            $uid,
            SkillSourceType::MARKETPLACE->value,
            self::MARKET_URL,
        );
    }

    private function md(string $name, string $body, string $description = 'd'): string
    {
        return sprintf("---\nname: %s\ndescription: %s\n---\n%s", $name, $description, $body);
    }

    private function mdWithTools(string $name, string $allowedToolsYaml, string $body, string $description = 'd'): string
    {
        return sprintf(
            "---\nname: %s\ndescription: %s\nallowed-tools: %s\n---\n%s",
            $name,
            $description,
            $allowedToolsYaml,
            $body,
        );
    }

    /**
     * Build a marketplace client whose index lists the given owner/repo plugin slugs and whose child
     * repos each expose a single skill at SKILL_A_PATH. Optionally force a child (owner,repo) to fail.
     *
     * @param list<string>                     $slugs      owner/repo plugin slugs to list in the index
     * @param array<string,GitHubApiException> $repoErrors per "owner/repo" failure to raise
     */
    private function marketGitHub(array $slugs, array $repoErrors = []): FakeGitHubClient
    {
        $plugins = array_map(static fn(string $slug): array => ['source' => $slug], $slugs);
        $repos = [];
        foreach ($slugs as $i => $slug) {
            $repos[$slug] = [
                'sha' => 'sha-' . $i,
                'tree' => [self::SKILL_A_PATH],
                'bodies' => [self::SKILL_A_PATH => $this->md('M' . $i, 'body ' . $i)],
            ];
        }

        return new FakeGitHubClient(
            repos: $repos,
            repoErrors: $repoErrors,
            indexes: [self::MARKET_URL => (string)json_encode(['plugins' => $plugins])],
        );
    }

    #[Test]
    public function marketplaceResolvesPlainRepoUrlToConventionIndex(): void
    {
        // A marketplace source URL given as a plain GitHub repo URL (not a raw
        // marketplace.json link) is auto-resolved to .claude-plugin/marketplace.json
        // on the repo's default branch, then synced like any marketplace.
        $gitHub = new FakeGitHubClient(repos: [
            'acme/market' => [
                'sha'    => 'market-head',
                'tree'   => [],
                'bodies' => [
                    '.claude-plugin/marketplace.json' => (string)json_encode(
                        ['plugins' => [['source' => 'acme/plugin1']]],
                    ),
                ],
            ],
            'acme/plugin1' => [
                'sha'    => 'plugin-head',
                'tree'   => [self::SKILL_A_PATH],
                'bodies' => [self::SKILL_A_PATH => $this->md('Resolved', 'from repo url')],
            ],
        ]);

        $source = $this->marketplaceSource();
        $source->setUrl('https://github.com/acme/market');

        $result = $this->service($gitHub)->sync($source);

        self::assertSame(SyncStatus::OK, $result->status);
        self::assertSame(1, $result->created);
        self::assertCount(1, $this->get(SkillRepository::class)->findAll());
    }

    #[Test]
    public function marketplaceConvertsGithubBlobUrlToRawIndex(): void
    {
        // A github.com /blob/ view URL (copied from the browser) is converted to
        // its raw equivalent and fetched as the index — not treated as a repo.
        $rawIndex = 'https://raw.githubusercontent.com/acme/market/main/.claude-plugin/marketplace.json';
        $gitHub   = new FakeGitHubClient(
            repos: [
                'acme/plugin1' => [
                    'sha'    => 'plugin-head',
                    'tree'   => [self::SKILL_A_PATH],
                    'bodies' => [self::SKILL_A_PATH => $this->md('Blob', 'from blob url')],
                ],
            ],
            indexes: [$rawIndex => (string)json_encode(['plugins' => [['source' => 'acme/plugin1']]])],
        );

        $source = $this->marketplaceSource();
        $source->setUrl('https://github.com/acme/market/blob/main/.claude-plugin/marketplace.json');

        $result = $this->service($gitHub)->sync($source);

        self::assertSame(SyncStatus::OK, $result->status);
        self::assertSame(1, $result->created);
    }

    #[Test]
    public function marketplaceRejectsUrlThatIsNeitherRepoNorRawIndex(): void
    {
        $source = $this->marketplaceSource();
        $source->setUrl('https://example.com/not-github');

        $result = $this->service(new FakeGitHubClient())->sync($source);

        self::assertSame(SyncStatus::ERROR, $result->status);
        self::assertNotSame([], $result->errors);
        self::assertStringContainsString('marketplace.json', implode("\n", $result->errors));
    }

    #[Test]
    public function repoSyncMaterializesSkillsDisabledByDefault(): void
    {
        $gitHub = new FakeGitHubClient(sha: 'sha1', tree: [self::SKILL_A_PATH, self::SKILL_B_PATH], bodies: [
            self::SKILL_A_PATH => $this->md('A', 'body a', 'da'),
            self::SKILL_B_PATH => $this->md('B', 'body b', 'db'),
        ]);
        $result = $this->service($gitHub)->sync($this->repoSource());

        self::assertSame(SyncStatus::OK, $result->status);
        self::assertSame(2, $result->created);
        $skills = $this->get(SkillRepository::class)->findBySource(10);
        self::assertCount(2, $skills);
        foreach ($skills as $skill) {
            self::assertFalse($skill->isEnabled(), 'multi-skill discovery must default disabled');
        }
    }

    #[Test]
    public function syncStoresAbsentAllowedToolsAsEmptyAndDeclaredAsJson(): void
    {
        // Absent front-matter key → '' (no opinion); a present declaration → its JSON,
        // including '[]' for a declared-empty fail-closed list.
        $gitHub = new FakeGitHubClient('sha1', [self::SKILL_A_PATH, self::SKILL_B_PATH, 'skills/c/SKILL.md'], [
            self::SKILL_A_PATH    => $this->md('A', 'body a'),
            self::SKILL_B_PATH    => $this->mdWithTools('B', '[]', 'body b'),
            'skills/c/SKILL.md'   => $this->mdWithTools('C', '[x]', 'body c'),
        ]);
        $this->service($gitHub)->sync($this->repoSource());

        $repo = $this->get(SkillRepository::class);

        $absent = $repo->findBySourceAndIdentifier(10, self::SKILL_A_ID);
        self::assertNotNull($absent);
        self::assertSame('', $absent->getAllowedTools(), 'absent allowed-tools front-matter stores empty string');

        $declaredEmpty = $repo->findBySourceAndIdentifier(10, self::SKILL_B_ID);
        self::assertNotNull($declaredEmpty);
        self::assertSame('[]', $declaredEmpty->getAllowedTools(), 'declared-empty allowed-tools stores "[]"');

        $declaredList = $repo->findBySourceAndIdentifier(10, '10:skills/c/SKILL.md');
        self::assertNotNull($declaredList);
        self::assertSame('["x"]', $declaredList->getAllowedTools(), 'a declared list stores its JSON encoding');
    }

    #[Test]
    public function syncSplitsStringFormAllowedToolsIntoList(): void
    {
        // A string-form declaration ("A, B") is a real list, not the
        // declared-empty (all-tools-off) list — it must be split into a list,
        // not silently collapsed to '[]'.
        $gitHub = new FakeGitHubClient('sha1', [self::SKILL_A_PATH], [
            self::SKILL_A_PATH => $this->mdWithTools('A', '"GetTca, GetEnv"', 'string-tools body content'),
        ]);
        $this->service($gitHub)->sync($this->repoSource());

        $skill = $this->get(SkillRepository::class)->findBySourceAndIdentifier(10, self::SKILL_A_ID);
        self::assertNotNull($skill);
        self::assertSame('["GetTca","GetEnv"]', $skill->getAllowedTools(), 'string-form allowed-tools splits into a list');
    }

    #[Test]
    public function resyncAutoDisablesEnabledSkillWhenBodyChanged(): void
    {
        $source = $this->repoSource();
        $first = new FakeGitHubClient('sha1', [self::SKILL_A_PATH], [self::SKILL_A_PATH => $this->md('A', 'v1')]);
        $this->service($first)->sync($source);

        // Admin enables it.
        $repo = $this->get(SkillRepository::class);
        $skill = $repo->findBySourceAndIdentifier(10, self::SKILL_A_ID);
        self::assertNotNull($skill);
        $skill->setEnabled(true);
        $repo->update($skill);
        $this->get(PersistenceManagerInterface::class)->persistAll();

        // Upstream changes the body.
        $second = new FakeGitHubClient('sha2', [self::SKILL_A_PATH], [self::SKILL_A_PATH => $this->md('A', 'v2')]);
        $result = $this->service($second)->sync($source);

        self::assertSame(1, $result->disabledOnChange);
        $reloaded = $repo->findBySourceAndIdentifier(10, self::SKILL_A_ID);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isEnabled(), 'changed enabled skill must auto-disable');
        self::assertSame('v2', trim((string)$reloaded->getBody()));
    }

    #[Test]
    public function resyncOrphansSkillRemovedUpstream(): void
    {
        $source = $this->repoSource();
        $this->service(new FakeGitHubClient('sha1', [self::SKILL_A_PATH, self::SKILL_B_PATH], [
            self::SKILL_A_PATH => $this->md('A', 'x'),
            self::SKILL_B_PATH => $this->md('B', 'y'),
        ]))->sync($source);

        $result = $this->service(new FakeGitHubClient('sha2', [self::SKILL_A_PATH], [
            self::SKILL_A_PATH => $this->md('A', 'x'),
        ]))->sync($source);

        self::assertSame(1, $result->orphaned);
        $b = $this->get(SkillRepository::class)->findBySourceAndIdentifier(10, self::SKILL_B_ID);
        self::assertNotNull($b);
        self::assertTrue($b->isOrphaned());
        self::assertFalse($b->isEnabled());
    }

    #[Test]
    public function parseErrorYieldsPartialStatusButImportsValidSkills(): void
    {
        $result = $this->service(new FakeGitHubClient('sha1', [self::SKILL_A_PATH, 'skills/bad/SKILL.md'], [
            self::SKILL_A_PATH => $this->md('A', 'ok'),
            'skills/bad/SKILL.md' => 'no frontmatter',
        ]))->sync($this->repoSource());

        self::assertSame(SyncStatus::PARTIAL, $result->status);
        self::assertSame(1, $result->created);
        self::assertCount(1, $result->errors);
    }

    #[Test]
    public function refusesConcurrentSync(): void
    {
        $source = $this->repoSource();
        $source->setSyncStatus(SyncStatus::SYNCING->value);
        $this->persistSourceHeartbeat($source, time());
        // fresh heartbeat → lock is considered active
        $result = $this->service(new FakeGitHubClient('sha1', [], []))->sync($source);
        self::assertSame(SyncStatus::SYNCING, $result->status);
        self::assertSame(['A sync is already running for this source.'], $result->errors);
    }

    #[Test]
    public function recoversFromStaleLock(): void
    {
        $source = $this->repoSource();
        $source->setSyncStatus(SyncStatus::SYNCING->value);
        $this->persistSourceHeartbeat($source, time() - 3600);
        // older than STALE_LOCK_SECONDS → stale, proceed
        $gitHub = new FakeGitHubClient('sha1', [self::SKILL_A_PATH], [
            self::SKILL_A_PATH => $this->md('A', 'body'),
        ]);
        $result = $this->service($gitHub)->sync($source);
        self::assertSame(SyncStatus::OK, $result->status);
        self::assertSame(1, $result->created);
    }

    #[Test]
    public function reclaimStaleLockFlipsInterruptedSyncToRetryableError(): void
    {
        // A SYNCING lock whose heartbeat is older than the stale window is an interrupted (crashed)
        // sync: reclaim flips it to ERROR so the list stops showing a wedged "Syncing" and a retry
        // is unblocked.
        $source = $this->repoSource();
        $source->setSyncStatus(SyncStatus::SYNCING->value);
        $this->persistSourceHeartbeat($source, time() - 3600);

        $reclaimed = $this->service(new FakeGitHubClient())->reclaimStaleLock($source);

        self::assertTrue($reclaimed);
        self::assertSame(SyncStatus::ERROR, $source->getSyncStatusEnum());
        self::assertStringContainsString('interrupted', $source->getSyncError());
    }

    #[Test]
    public function reclaimStaleLockLeavesAGenuinelyRunningSyncUntouched(): void
    {
        // A fresh heartbeat means a sync is actually in progress; reclaim must not steal its lock.
        $source = $this->repoSource();
        $source->setSyncStatus(SyncStatus::SYNCING->value);
        $this->persistSourceHeartbeat($source, time());

        $reclaimed = $this->service(new FakeGitHubClient())->reclaimStaleLock($source);

        self::assertFalse($reclaimed);
        self::assertSame(SyncStatus::SYNCING, $source->getSyncStatusEnum());
    }

    #[Test]
    public function reclaimStaleLockIgnoresACompletedSource(): void
    {
        // An OK source with an old lastSynced is a finished sync, not a stale lock: it must not be
        // flipped to ERROR just because its last-synced timestamp is old.
        $source = $this->repoSource();
        $source->setSyncStatus(SyncStatus::OK->value);
        $this->persistSourceHeartbeat($source, time() - 3600);

        $reclaimed = $this->service(new FakeGitHubClient())->reclaimStaleLock($source);

        self::assertFalse($reclaimed);
        self::assertSame(SyncStatus::OK, $source->getSyncStatusEnum());
    }

    #[Test]
    public function heartbeatDuringCollectDoesNotDisturbTheSyncFlow(): void
    {
        // With a zero heartbeat interval the lock is re-persisted on every file mid-collect; the
        // create/persist flow must still complete correctly (the mid-collect flush of the source row
        // must not leak partial state or disturb the later skill upserts).
        $gitHub = new FakeGitHubClient('sha1', [self::SKILL_A_PATH, self::SKILL_B_PATH], [
            self::SKILL_A_PATH => $this->md('A', 'body a'),
            self::SKILL_B_PATH => $this->md('B', 'body b'),
        ]);

        $result = $this->service($gitHub, heartbeatSeconds: 0)->sync($this->repoSource());

        self::assertSame(SyncStatus::OK, $result->status);
        self::assertSame(2, $result->created);
        self::assertCount(2, $this->get(SkillRepository::class)->findBySource(10));
    }

    #[Test]
    public function doesNotOrphanSkillWhenItsFileBecomesUnparseable(): void
    {
        $source = $this->repoSource();
        $this->service(new FakeGitHubClient('sha1', [self::SKILL_A_PATH], [
            self::SKILL_A_PATH => $this->md('A', 'v1'),
        ]))->sync($source);
        $repo = $this->get(SkillRepository::class);
        self::assertNotNull($repo->findBySourceAndIdentifier(10, self::SKILL_A_ID));

        // The file is STILL PRESENT upstream but can no longer be parsed.
        $result = $this->service(new FakeGitHubClient('sha2', [self::SKILL_A_PATH], [
            self::SKILL_A_PATH => 'broken, no front-matter',
        ]))->sync($source);

        self::assertSame(SyncStatus::PARTIAL, $result->status);
        self::assertSame(0, $result->orphaned, 'a present-but-unparseable file must not orphan the skill');
        $reloaded = $repo->findBySourceAndIdentifier(10, self::SKILL_A_ID);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isOrphaned());
    }

    #[Test]
    public function persistsSyncStateForRealSource(): void
    {
        $source = new SkillSource();
        $source->setType(SkillSourceType::REPO->value);
        $source->setUrl(self::REPO_URL);
        $source->setRef('main');

        $sourceRepository = $this->get(SkillSourceRepository::class);
        $sourceRepository->add($source);
        $this->get(PersistenceManagerInterface::class)->persistAll();
        $uid = $source->getUid();
        self::assertNotNull($uid);

        $gitHub = new FakeGitHubClient('cafe1234', [self::SKILL_A_PATH], [
            self::SKILL_A_PATH => $this->md('A', 'body'),
        ]);
        $result = $this->service($gitHub)->sync($source);
        self::assertSame(SyncStatus::OK, $result->status);

        $this->get(PersistenceManagerInterface::class)->persistAll();
        $reloaded = $sourceRepository->findByUid($uid);
        self::assertNotNull($reloaded);
        self::assertSame(SyncStatus::OK, $reloaded->getSyncStatusEnum());
        self::assertSame('cafe1234', $reloaded->getPinnedSha());
        self::assertGreaterThan(0, $reloaded->getLastSynced());
    }

    #[Test]
    public function singleFileLifecycleCreatesEnabledThenAutoDisablesThenOrphansOn404(): void
    {
        $source = $this->singleFileSource();
        $repo = $this->get(SkillRepository::class);

        // Create: a single file is enabled by default.
        $this->service(new FakeGitHubClient('sha1', [], ['SKILL.md' => $this->md('S', 'v1')]))->sync($source);
        $created = $repo->findBySourceAndIdentifier(20, '20:SKILL.md');
        self::assertNotNull($created);
        self::assertTrue($created->isEnabled(), 'a single_file skill is enabled on first import');

        // Body change: the enabled skill auto-disables.
        $changed = $this->service(new FakeGitHubClient('sha2', [], ['SKILL.md' => $this->md('S', 'v2')]))->sync($source);
        self::assertSame(1, $changed->disabledOnChange);
        $afterChange = $repo->findBySourceAndIdentifier(20, '20:SKILL.md');
        self::assertNotNull($afterChange);
        self::assertFalse($afterChange->isEnabled());

        // Upstream 404 at the resolved commit: the file is gone, so the skill is orphaned.
        $gone = $this->service(new FakeGitHubClient('sha3', [], []))->sync($source);
        self::assertSame(SyncStatus::PARTIAL, $gone->status);
        self::assertSame(1, $gone->orphaned, 'a 404 at the resolved commit orphans the single file');
        $orphan = $repo->findBySourceAndIdentifier(20, '20:SKILL.md');
        self::assertNotNull($orphan);
        self::assertTrue($orphan->isOrphaned());
        self::assertFalse($orphan->isEnabled());
    }

    #[Test]
    public function marketplaceNamespacesSkillsByPluginRepo(): void
    {
        $result = $this->service($this->marketGitHub([self::PLUGIN_A, self::PLUGIN_B]))->sync($this->marketplaceSource());

        self::assertSame(SyncStatus::OK, $result->status);
        self::assertSame(2, $result->created);
        $repo = $this->get(SkillRepository::class);
        self::assertNotNull($repo->findBySourceAndIdentifier(30, self::MARKET_A_ID));
        self::assertNotNull($repo->findBySourceAndIdentifier(30, self::MARKET_B_ID));
    }

    #[Test]
    public function marketplaceProtectsSkillsOfAnUnreachableChildRepo(): void
    {
        $source = $this->marketplaceSource();
        $this->service($this->marketGitHub([self::PLUGIN_A, self::PLUGIN_B]))->sync($source);

        // The second child repo is unreachable this run (a transient, non-rate-limit failure).
        $result = $this->service($this->marketGitHub(
            [self::PLUGIN_A, self::PLUGIN_B],
            [self::PLUGIN_B => GitHubApiException::forStatus('https://api.github.com/repos/p2/repob/commits/HEAD', 500)],
        ))->sync($source);

        self::assertSame(SyncStatus::PARTIAL, $result->status);
        self::assertSame(0, $result->orphaned, 'a listed-but-unreachable plugin must not orphan its skills');
        $repo = $this->get(SkillRepository::class);
        $protected = $repo->findBySourceAndIdentifier(30, self::MARKET_B_ID);
        self::assertNotNull($protected);
        self::assertFalse($protected->isOrphaned());
        self::assertNotNull($repo->findBySourceAndIdentifier(30, self::MARKET_A_ID));
    }

    #[Test]
    public function marketplaceOrphansSkillsOfADeListedPlugin(): void
    {
        $source = $this->marketplaceSource();
        $this->service($this->marketGitHub([self::PLUGIN_A, self::PLUGIN_B]))->sync($source);

        // The second plugin is removed from the index entirely (de-listed).
        $result = $this->service($this->marketGitHub([self::PLUGIN_A]))->sync($source);

        self::assertSame(1, $result->orphaned, 'a de-listed plugin must orphan its skills');
        $repo = $this->get(SkillRepository::class);
        $orphan = $repo->findBySourceAndIdentifier(30, self::MARKET_B_ID);
        self::assertNotNull($orphan);
        self::assertTrue($orphan->isOrphaned());
        self::assertFalse($orphan->isEnabled());
        $kept = $repo->findBySourceAndIdentifier(30, self::MARKET_A_ID);
        self::assertNotNull($kept);
        self::assertFalse($kept->isOrphaned());
    }

    #[Test]
    public function marketplaceDedupsDuplicatePluginEntriesFirstWins(): void
    {
        $result = $this->service($this->marketGitHub([self::PLUGIN_A, self::PLUGIN_A]))->sync($this->marketplaceSource());

        self::assertSame(SyncStatus::PARTIAL, $result->status);
        self::assertSame(1, $result->created, 'a duplicate plugin entry must not create the skill twice');
        self::assertContains('duplicate marketplace plugin "p1/repoa", first wins', $result->errors);
        self::assertCount(1, $this->get(SkillRepository::class)->findBySource(30));
    }

    #[Test]
    public function rateLimitMidCollectFailsWithErrorAndNoOrphaning(): void
    {
        $source = $this->marketplaceSource();
        $this->service($this->marketGitHub([self::PLUGIN_A, self::PLUGIN_B]))->sync($source);

        // The second child repo rate-limits mid-collect: the whole sync aborts as ERROR.
        $result = $this->service($this->marketGitHub(
            [self::PLUGIN_A, self::PLUGIN_B],
            [self::PLUGIN_B => GitHubApiException::forRateLimit(0)],
        ))->sync($source);

        self::assertSame(SyncStatus::ERROR, $result->status);
        self::assertSame(0, $result->orphaned, 'a rate-limit abort must not orphan anything');
        // The previously-synced skills are left untouched.
        $repo = $this->get(SkillRepository::class);
        self::assertCount(2, $repo->findBySource(30));
        $a = $repo->findBySourceAndIdentifier(30, self::MARKET_A_ID);
        self::assertNotNull($a);
        self::assertFalse($a->isOrphaned());
    }

    #[Test]
    public function perSyncFileBoundStopsCollectionEarlyAsPartial(): void
    {
        $gitHub = new FakeGitHubClient('sha1', [self::SKILL_A_PATH, self::SKILL_B_PATH, 'skills/c/SKILL.md'], [
            self::SKILL_A_PATH => $this->md('A', 'a'),
            self::SKILL_B_PATH => $this->md('B', 'b'),
            'skills/c/SKILL.md' => $this->md('C', 'c'),
        ]);
        $result = $this->service($gitHub, maxFiles: 1)->sync($this->repoSource());

        self::assertSame(SyncStatus::PARTIAL, $result->status);
        self::assertSame(1, $result->created, 'collection must stop after the file bound is hit');
        self::assertStringContainsString('Per-sync limit reached', implode("\n", $result->errors));
    }

    #[Test]
    public function aStaleSourceSnapshotCannotStartWhileThePersistedSourceIsSyncing(): void
    {
        $source = $this->repoSource();
        self::assertNotNull($source->getUid());
        $staleSnapshot = clone $source;
        $second = $this->service(new FakeGitHubClient('second', [], []));
        $secondResult = null;
        $firstGitHub = $this->createMock(GitHubClientInterface::class);
        $firstGitHub
            ->expects(self::once())
            ->method('resolveSha')
            ->willReturnCallback(
                function () use ($source, $staleSnapshot, $second, &$secondResult): string {
                    $connection = $this->getConnectionPool()->getConnectionForTable(
                        'tx_nrllm_skill_source',
                    );
                    self::assertSame(
                        SyncStatus::SYNCING->value,
                        $connection->select(
                            ['sync_status'],
                            'tx_nrllm_skill_source',
                            ['uid' => $source->getUid()],
                        )->fetchOne(),
                    );
                    self::assertNotSame(
                        SyncStatus::SYNCING,
                        $staleSnapshot->getSyncStatusEnum(),
                    );
                    $secondResult = $second->sync($staleSnapshot);
                    return 'first';
                },
            );
        $firstGitHub->expects(self::once())->method('listTree')->willReturn([]);
        $first = new SkillSyncService(
            $firstGitHub,
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->get(SkillRepository::class),
            $this->get(PersistenceManagerInterface::class),
            new NullLogger(),
            new SkillSyncLeaseRepository(
                $this->get(ConnectionPool::class),
            ),
        );
        $firstResult = $first->sync($source);
        self::assertSame(SyncStatus::OK, $firstResult->status);
        self::assertNotNull($secondResult);
        self::assertSame(
            SyncStatus::SYNCING,
            $secondResult->status,
            'The second caller must observe the persisted live lock, not its stale entity snapshot.',
        );
        self::assertSame(0, $secondResult->created);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absentSourceCases(): iterable
    {
        yield 'unsaved' => ['unsaved'];
        yield 'zero uid' => ['zero'];
        yield 'negative uid' => ['negative'];
        yield 'missing uid' => ['missing'];
        yield 'physically deleted' => ['physical'];
        yield 'TYPO3 soft deleted' => ['soft'];
    }

    #[Test]
    #[DataProvider('absentSourceCases')]
    public function absentSourcesDoNotContactGitHubOrInsertRows(
        string $kind,
    ): void {
        $source = new SkillSource();
        $source->setType(SkillSourceType::REPO->value);
        $source->setUrl(self::REPO_URL);

        $connection = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_skill_source');
        if ($kind === 'physical' || $kind === 'soft') {
            $repository = $this->get(SkillSourceRepository::class);
            $repository->add($source);
            $this->get(PersistenceManagerInterface::class)->persistAll();
            if ($kind === 'physical') {
                $connection->delete(
                    'tx_nrllm_skill_source',
                    ['uid' => $source->getUid()],
                );
            } else {
                $connection->update(
                    'tx_nrllm_skill_source',
                    ['deleted' => 1],
                    ['uid' => $source->getUid()],
                );
            }
        } elseif ($kind !== 'unsaved') {
            $source->_setProperty(
                'uid',
                match ($kind) {
                    'zero' => 0,
                    'negative' => -1,
                    default => 1337,
                },
            );
        }

        $sourcesBefore = $connection->count('*', 'tx_nrllm_skill_source', []);
        $contacts = 0;
        $github = self::createMock(GitHubClientInterface::class);
        $github
            ->method('resolveSha')
            ->willReturnCallback(
                static function () use (&$contacts): string {
                    ++$contacts;
                    return 'sha';
                },
            );
        $github->method('listTree')->willReturn([]);
        $service = new SkillSyncService(
            $github,
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->get(SkillRepository::class),
            $this->get(PersistenceManagerInterface::class),
            new NullLogger(),
            new SkillSyncLeaseRepository(
                $this->get(ConnectionPool::class),
            ),
        );
        $result = $service->sync($source);
        self::assertSame(
            0,
            $contacts,
            'A missing or deleted persisted source cannot authorize remote contact.',
        );
        self::assertSame(SyncStatus::ERROR, $result->status);
        self::assertSame(
            $sourcesBefore,
            $connection->count('*', 'tx_nrllm_skill_source', []),
        );
        self::assertSame(
            0,
            $this
                ->get(ConnectionPool::class)
                ->getConnectionForTable('tx_nrllm_skill')
                ->count('*', 'tx_nrllm_skill', []),
        );
        self::assertSame(
            [0, 0, 0, 0, 0],
            [
                $result->created,
                $result->updated,
                $result->disabledOnChange,
                $result->orphaned,
                $result->injectionBlocked,
            ],
        );
    }

    private function persistedSource(
        int $uid,
        string $type,
        string $url,
        string $ref = '',
    ): SkillSource {
        $connection = $this
            ->getConnectionPool()
            ->getConnectionForTable('tx_nrllm_skill_source');
        if ($connection->count('*', 'tx_nrllm_skill_source', ['uid' => $uid]) === 0) {
            $connection->insert(
                'tx_nrllm_skill_source',
                ['uid' => $uid, 'type' => $type, 'url' => $url, 'ref' => $ref],
            );
        }

        $source = $this->get(SkillSourceRepository::class)->findByUid($uid);
        self::assertInstanceOf(SkillSource::class, $source);
        return $source;
    }

    private function persistSourceHeartbeat(
        SkillSource $source,
        int $timestamp,
    ): void {
        $source->setLastSynced($timestamp);
        $this
            ->getConnectionPool()
            ->getConnectionForTable('tx_nrllm_skill_source')
            ->update(
                'tx_nrllm_skill_source',
                [
                    'sync_status' => $source->getSyncStatus(),
                    'last_synced' => $timestamp,
                ],
                ['uid' => $source->getUid()],
            );
    }

    /**
     * @return iterable<string,array{string,int}>
     */
    public static function lossDuringFetchCases(): iterable
    {
        yield 'same second successor; throttled heartbeat' => ['successor', 30];
        yield 'same second successor; immediate heartbeat' => ['successor', 0];
        yield 'expired lease after slow fetch' => ['expired', 30];
        yield 'source physically deleted during fetch' => ['physical', 30];
        yield 'source soft deleted during fetch' => ['soft', 30];
    }

    #[Test]
    #[DataProvider('lossDuringFetchCases')]
    public function lostLeaseAfterRemoteFetchCannotPublishChangesOrOrphans(
        string $loss,
        int $heartbeatSeconds,
    ): void {
        $source = $this->repoSource();
        $seed = $this
            ->service(
                new FakeGitHubClient(
                    'seed',
                    [self::SKILL_A_PATH, self::SKILL_B_PATH],
                    [
                        self::SKILL_A_PATH => $this->md('A', 'old A'),
                        self::SKILL_B_PATH => $this->md('B', 'old B'),
                    ],
                ),
            )
            ->sync($source);
        self::assertSame(SyncStatus::OK, $seed->status);
        $skills = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill');
        $skills->update('tx_nrllm_skill', ['enabled' => 1], ['source' => 10]);
        $this->get(PersistenceManagerInterface::class)->clearState();
        $source = $this->repoSource();
        $before = $skills
            ->select(['*'], 'tx_nrllm_skill', ['source' => 10], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();
        $sourceConnection = $this
            ->getConnectionPool()
            ->getConnectionForTable('tx_nrllm_skill_source');
        $stateAtLoss = null;
        $github = $this->createMock(GitHubClientInterface::class);
        $github
            ->expects(self::once())
            ->method('resolveSha')
            ->willReturnCallback(
                function () use ($sourceConnection): string {
                    self::assertSame(
                        0,
                        $sourceConnection->getTransactionNestingLevel(),
                        'Remote collection must happen outside the publication transaction.',
                    );
                    return 'attempt';
                },
            );
        $github->method('listTree')->willReturn([self::SKILL_A_PATH]);
        $github
            ->expects(self::once())
            ->method('fetchRawBySha')
            ->willReturnCallback(
                function () use ($loss, $sourceConnection, &$stateAtLoss): string {
                    if ($loss === 'physical') {
                        $sourceConnection->delete(
                            'tx_nrllm_skill_source',
                            ['uid' => 10],
                        );
                    } elseif ($loss === 'soft') {
                        $sourceConnection->update(
                            'tx_nrllm_skill_source',
                            ['deleted' => 1],
                            ['uid' => 10],
                        );
                    } elseif ($loss === 'expired') {
                        $sourceConnection->update(
                            'tx_nrllm_skill_source',
                            ['last_synced' => time() - 181],
                            ['uid' => 10],
                        );
                    } else {
                        $sourceConnection->update(
                            'tx_nrllm_skill_source',
                            [
                                'sync_lock_token' => str_repeat('b', 64),
                                'sync_error' => 'successor diagnostic',
                                'pinned_sha' => 'successor-sha',
                            ],
                            ['uid' => 10],
                        );
                    }

                    $stateAtLoss = $sourceConnection->select(
                        ['*'],
                        'tx_nrllm_skill_source',
                        ['uid' => 10],
                    )->fetchAssociative();
                    return $this->md('A', 'unpublished replacement');
                },
            );
        $result = $this
            ->service($github, heartbeatSeconds: $heartbeatSeconds)
            ->sync($source);
        self::assertSame(SyncStatus::ERROR, $result->status);
        self::assertStringContainsString(
            'lease was lost',
            implode("\n", $result->errors),
        );
        self::assertSame(
            [0, 0, 0, 0, 0],
            [
                $result->created,
                $result->updated,
                $result->disabledOnChange,
                $result->orphaned,
                $result->injectionBlocked,
            ],
        );
        self::assertSame(
            $before,
            $skills
                ->select(['*'], 'tx_nrllm_skill', ['source' => 10], [], ['uid' => 'ASC'])
                ->fetchAllAssociative(),
            'Collected changes and missing upstream paths must not alter the prior published skills.',
        );
        if ($loss !== 'expired') {
            self::assertSame(
                $stateAtLoss,
                $sourceConnection
                    ->select(['*'], 'tx_nrllm_skill_source', ['uid' => 10])
                    ->fetchAssociative(),
                'An old worker cannot change or recreate its successor/deleted source.',
            );
        } else {
            self::assertSame(
                SyncStatus::ERROR->value,
                $sourceConnection
                    ->select(['sync_status'], 'tx_nrllm_skill_source', ['uid' => 10])
                    ->fetchOne(),
            );
        }

        $this->get(PersistenceManagerInterface::class)->persistAll();
        if ($loss === 'successor') {
            self::assertSame(
                $stateAtLoss,
                $sourceConnection
                    ->select(['*'], 'tx_nrllm_skill_source', ['uid' => 10])
                    ->fetchAssociative(),
                'An ordinary later Extbase flush must not overwrite successor bookkeeping.',
            );
        }
    }

    #[Test]
    public function staleLoadedSourceCannotReclaimPersistedRenewalOrCompletion(): void
    {
        $source = $this->repoSource();
        $source->setSyncStatus(SyncStatus::SYNCING->value);
        $source->setLastSynced(time() - 3600);

        $connection = $this
            ->getConnectionPool()
            ->getConnectionForTable('tx_nrllm_skill_source');
        $service = $this->service(new FakeGitHubClient());
        $connection->update(
            'tx_nrllm_skill_source',
            [
                'sync_status' => SyncStatus::SYNCING->value,
                'last_synced' => time(),
                'sync_lock_token' => str_repeat('b', 64),
            ],
            ['uid' => 10],
        );
        $renewed = $connection
            ->select(['*'], 'tx_nrllm_skill_source', ['uid' => 10])
            ->fetchAssociative();
        self::assertFalse($service->reclaimStaleLock($source));
        self::assertSame(
            $renewed,
            $connection
                ->select(['*'], 'tx_nrllm_skill_source', ['uid' => 10])
                ->fetchAssociative(),
        );
        self::assertSame(SyncStatus::SYNCING, $source->getSyncStatusEnum());
        $source->setLastSynced(time() - 3600);
        $connection->update(
            'tx_nrllm_skill_source',
            [
                'sync_status' => SyncStatus::OK->value,
                'last_synced' => time() - 3600,
                'sync_lock_token' => '',
            ],
            ['uid' => 10],
        );
        $completed = $connection
            ->select(['*'], 'tx_nrllm_skill_source', ['uid' => 10])
            ->fetchAssociative();
        self::assertFalse($service->reclaimStaleLock($source));
        self::assertSame(
            $completed,
            $connection
                ->select(['*'], 'tx_nrllm_skill_source', ['uid' => 10])
                ->fetchAssociative(),
        );
        self::assertSame(SyncStatus::OK, $source->getSyncStatusEnum());
    }

    #[Test]
    public function failedPublicationRollsBackAttemptedWritesAndReportsZeroCounters(): void
    {
        $source = $this->repoSource();
        $this
            ->service(
                new FakeGitHubClient(
                    'seed',
                    [self::SKILL_A_PATH, self::SKILL_B_PATH],
                    [
                        self::SKILL_A_PATH => $this->md('A', 'old A'),
                        self::SKILL_B_PATH => $this->md('B', 'old B'),
                    ],
                ),
            )
            ->sync($source);
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill');
        $connection->update(
            'tx_nrllm_skill',
            ['enabled' => 1],
            ['source' => 10],
        );
        $realPersistence = $this->get(PersistenceManagerInterface::class);
        $realPersistence->clearState();

        $source = $this->repoSource();
        $before = $connection
            ->select(['*'], 'tx_nrllm_skill', ['source' => 10], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();
        $failure = $this->createMock(PersistenceManagerInterface::class);
        $failure
            ->expects(self::once())
            ->method('persistAll')
            ->willReturnCallback(
                function () use ($realPersistence, $connection): never {
                    $realPersistence->persistAll();
                    self::assertGreaterThan(
                        0,
                        $connection->getTransactionNestingLevel(),
                    );
                    self::assertSame(
                        3,
                        $connection->count(
                            '*',
                            'tx_nrllm_skill',
                            ['source' => 10],
                        ),
                        'The controlled fault occurs after an actual new row was written.',
                    );
                    self::assertSame(
                        'changed A',
                        $connection->select(
                            ['body'],
                            'tx_nrllm_skill',
                            ['identifier' => self::SKILL_A_ID],
                        )->fetchOne(),
                    );
                    self::assertSame(
                        1,
                        (int)$connection->select(
                            ['orphaned'],
                            'tx_nrllm_skill',
                            ['identifier' => self::SKILL_B_ID],
                        )->fetchOne(),
                    );
                    throw new RuntimeException('controlled publication failure', 221);
                },
            );
        $failure
            ->expects(self::once())
            ->method('clearState')
            ->willReturnCallback(
                static function () use ($realPersistence): void {
                    $realPersistence->clearState();
                },
            );
        $github = new FakeGitHubClient(
            'attempt',
            [self::SKILL_A_PATH, 'skills/c/SKILL.md'],
            [
                self::SKILL_A_PATH => $this->md('A', 'changed A'),
                'skills/c/SKILL.md' => $this->md('C', 'new C'),
            ],
        );
        $result = $this->service($github, persistence: $failure)->sync($source);
        self::assertSame(SyncStatus::ERROR, $result->status);
        self::assertSame(['controlled publication failure'], $result->errors);
        self::assertSame(
            [0, 0, 0, 0, 0],
            [
                $result->created,
                $result->updated,
                $result->disabledOnChange,
                $result->orphaned,
                $result->injectionBlocked,
            ],
        );
        self::assertSame(
            $before,
            $connection
                ->select(['*'], 'tx_nrllm_skill', ['source' => 10], [], ['uid' => 'ASC'])
                ->fetchAllAssociative(),
        );
        $sourceState = $connection
            ->select(
                ['sync_status', 'sync_lock_token', 'pinned_sha'],
                'tx_nrllm_skill_source',
                ['uid' => 10],
            )
            ->fetchAssociative();
        self::assertSame(
            [
                'sync_status' => SyncStatus::ERROR->value,
                'sync_lock_token' => '',
                'pinned_sha' => 'seed',
            ],
            $sourceState,
        );
        $retry = $this->service($github)->sync($this->repoSource());
        self::assertSame(SyncStatus::OK, $retry->status);
        self::assertSame(1, $retry->created);
        self::assertSame(1, $retry->disabledOnChange);
        self::assertSame(1, $retry->orphaned);
        self::assertSame(
            'changed A',
            $connection
                ->select(['body'], 'tx_nrllm_skill', ['identifier' => self::SKILL_A_ID])
                ->fetchOne(),
        );
    }

    #[Test]
    public function splitDatabaseMappingFailsBeforeRemoteContact(): void
    {
        $source = $this->repoSource();
        $oldConfiguration = $GLOBALS['TYPO3_CONF_VARS'];
        self::assertIsArray($oldConfiguration);
        $db = $oldConfiguration['DB'];
        self::assertIsArray($db);
        $connections = $db['Connections'];
        self::assertIsArray($connections);
        $mapping = $db['TableMapping'] ?? [];
        self::assertIsArray($mapping);
        $connection = $this
            ->getConnectionPool()
            ->getConnectionForTable('tx_nrllm_skill_source');
        $before = $connection
            ->select(['*'], 'tx_nrllm_skill_source', ['uid' => 10])
            ->fetchAssociative();
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::never())->method('resolveSha');
        try {
            $configuration = $oldConfiguration;
            $configuration['DB'] = array_replace(
                $db,
                [
                    'Connections' => array_replace(
                        $connections,
                        ['AuditLeaseSplit' => $connections['Default']],
                    ),
                    'TableMapping' => array_replace(
                        $mapping,
                        ['tx_nrllm_skill_audit' => 'AuditLeaseSplit'],
                    ),
                ],
            );
            $GLOBALS['TYPO3_CONF_VARS'] = $configuration;
            $result = $this->service($github)->sync($source);
            self::assertSame(SyncStatus::ERROR, $result->status);
            self::assertStringContainsString(
                'same database connection',
                implode("\n", $result->errors),
            );
            self::assertSame(
                $before,
                $connection
                    ->select(['*'], 'tx_nrllm_skill_source', ['uid' => 10])
                    ->fetchAssociative(),
            );
            self::assertSame(0, $connection->count('*', 'tx_nrllm_skill', []));
        } finally {
            $GLOBALS['TYPO3_CONF_VARS'] = $oldConfiguration;
        }
    }
}
