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
    private function service(FakeGitHubClient $gitHub): SkillSyncService
    {
        return new SkillSyncService(
            $gitHub,
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->get(SkillRepository::class),
            $this->get(SkillSourceRepository::class),
            $this->get(PersistenceManagerInterface::class),
        );
    }

    private function repoSource(int $uid = 10): SkillSource
    {
        $source = new SkillSource();
        $source->_setProperty('uid', $uid);
        $source->setType(SkillSourceType::REPO);
        $source->setUrl('https://github.com/acme/skills');
        $source->setRef('main');
        return $source;
    }

    #[Test]
    public function repoSyncMaterializesSkillsDisabledByDefault(): void
    {
        $gitHub = new FakeGitHubClient(sha: 'sha1', tree: ['skills/a/SKILL.md', 'skills/b/SKILL.md'], bodies: [
            'skills/a/SKILL.md' => "---\nname: A\ndescription: da\n---\nbody a",
            'skills/b/SKILL.md' => "---\nname: B\ndescription: db\n---\nbody b",
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
    public function resyncAutoDisablesEnabledSkillWhenBodyChanged(): void
    {
        $source = $this->repoSource();
        $first = new FakeGitHubClient('sha1', ['skills/a/SKILL.md'], ['skills/a/SKILL.md' => "---\nname: A\ndescription: d\n---\nv1"]);
        $this->service($first)->sync($source);

        // Admin enables it.
        $repo = $this->get(SkillRepository::class);
        $skill = $repo->findBySourceAndIdentifier(10, '10:skills/a/SKILL.md');
        self::assertNotNull($skill);
        $skill->setEnabled(true);
        $repo->update($skill);
        $this->get(PersistenceManagerInterface::class)->persistAll();

        // Upstream changes the body.
        $second = new FakeGitHubClient('sha2', ['skills/a/SKILL.md'], ['skills/a/SKILL.md' => "---\nname: A\ndescription: d\n---\nv2"]);
        $result = $this->service($second)->sync($source);

        self::assertSame(1, $result->disabledOnChange);
        $reloaded = $repo->findBySourceAndIdentifier(10, '10:skills/a/SKILL.md');
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isEnabled(), 'changed enabled skill must auto-disable');
        self::assertSame('v2', trim($reloaded->getBody()));
    }

    #[Test]
    public function resyncOrphansSkillRemovedUpstream(): void
    {
        $source = $this->repoSource();
        $this->service(new FakeGitHubClient('sha1', ['skills/a/SKILL.md', 'skills/b/SKILL.md'], [
            'skills/a/SKILL.md' => "---\nname: A\ndescription: d\n---\nx",
            'skills/b/SKILL.md' => "---\nname: B\ndescription: d\n---\ny",
        ]))->sync($source);

        $result = $this->service(new FakeGitHubClient('sha2', ['skills/a/SKILL.md'], [
            'skills/a/SKILL.md' => "---\nname: A\ndescription: d\n---\nx",
        ]))->sync($source);

        self::assertSame(1, $result->orphaned);
        $b = $this->get(SkillRepository::class)->findBySourceAndIdentifier(10, '10:skills/b/SKILL.md');
        self::assertNotNull($b);
        self::assertTrue($b->isOrphaned());
        self::assertFalse($b->isEnabled());
    }

    #[Test]
    public function parseErrorYieldsPartialStatusButImportsValidSkills(): void
    {
        $result = $this->service(new FakeGitHubClient('sha1', ['skills/a/SKILL.md', 'skills/bad/SKILL.md'], [
            'skills/a/SKILL.md' => "---\nname: A\ndescription: d\n---\nok",
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
        $source->setSyncStatus(SyncStatus::SYNCING);
        $result = $this->service(new FakeGitHubClient('sha1', [], []))->sync($source);
        self::assertSame(SyncStatus::SYNCING, $result->status);
        self::assertSame(['A sync is already running for this source.'], $result->errors);
    }
}
