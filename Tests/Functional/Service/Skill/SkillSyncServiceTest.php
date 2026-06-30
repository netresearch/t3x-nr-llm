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
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use Netresearch\NrLlm\Service\Skill\SkillDiscovery;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Skill\Fixtures\FakeGitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

    private function service(FakeGitHubClient $gitHub, int $maxFiles = 500, int $maxSeconds = 120): SkillSyncService
    {
        return new SkillSyncService(
            $gitHub,
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->get(SkillRepository::class),
            $this->get(SkillSourceRepository::class),
            $this->get(PersistenceManagerInterface::class),
            $maxFiles,
            $maxSeconds,
        );
    }

    #[Test]
    public function unknownStoredTypeYieldsErrorStatus(): void
    {
        // A malformed/unsupported type column must fail closed (clear ERROR), not be
        // silently treated as a repo — the point of the defensive getTypeEnum() pattern.
        $source = new SkillSource();
        $source->_setProperty('uid', 40);
        $source->setType('bogus-type');
        $source->setUrl(self::REPO_URL);

        $result = $this->service($this->marketGitHub([]))->sync($source);

        self::assertSame(SyncStatus::ERROR, $result->status);
        self::assertNotSame([], $result->errors);
    }

    private function repoSource(int $uid = 10): SkillSource
    {
        $source = new SkillSource();
        $source->_setProperty('uid', $uid);
        $source->setType(SkillSourceType::REPO->value);
        $source->setUrl(self::REPO_URL);
        $source->setRef('main');
        return $source;
    }

    private function singleFileSource(int $uid = 20): SkillSource
    {
        $source = new SkillSource();
        $source->_setProperty('uid', $uid);
        $source->setType(SkillSourceType::SINGLE_FILE->value);
        $source->setUrl(self::SINGLE_FILE_URL);
        $source->setRef('main');
        return $source;
    }

    private function marketplaceSource(int $uid = 30): SkillSource
    {
        $source = new SkillSource();
        $source->_setProperty('uid', $uid);
        $source->setType(SkillSourceType::MARKETPLACE->value);
        $source->setUrl(self::MARKET_URL);
        return $source;
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
        $source->setLastSynced(time()); // fresh heartbeat → lock is considered active
        $result = $this->service(new FakeGitHubClient('sha1', [], []))->sync($source);
        self::assertSame(SyncStatus::SYNCING, $result->status);
        self::assertSame(['A sync is already running for this source.'], $result->errors);
    }

    #[Test]
    public function recoversFromStaleLock(): void
    {
        $source = $this->repoSource();
        $source->setSyncStatus(SyncStatus::SYNCING->value);
        $source->setLastSynced(time() - 3600); // older than STALE_LOCK_SECONDS → stale, proceed
        $gitHub = new FakeGitHubClient('sha1', [self::SKILL_A_PATH], [
            self::SKILL_A_PATH => $this->md('A', 'body'),
        ]);
        $result = $this->service($gitHub)->sync($source);
        self::assertSame(SyncStatus::OK, $result->status);
        self::assertSame(1, $result->created);
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
}
