<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Domain\ValueObject\SyncResult;
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use Netresearch\NrLlm\Service\Skill\SkillDiscovery;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Skill\Fixtures\FakeGitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The sync's change test compares version digests (ADR-214 item 1): a
 * frontmatter-only change is a change, and a legacy row is compared like
 * with like on its first sync after the upgrade.
 */
#[CoversClass(SkillSyncService::class)]
final class SkillVersionDigestSyncTest extends AbstractFunctionalTestCase
{
    private const PATH = 'skills/a/SKILL.md';

    private const ID = '10:skills/a/SKILL.md';

    #[Test]
    public function theSyncWritesTheDigestOfTheVersionItStored(): void
    {
        $this->sync($this->md("description: d\nprocess: true", 'body'));

        $skill = $this->stored();
        self::assertTrue(SkillVersionDigest::isWellFormed($skill->getVersionDigest()));
        self::assertSame(SkillVersionDigest::of($skill), $skill->getVersionDigest());
        self::assertTrue($skill->isProcess());
    }

    #[Test]
    public function anUnchangedVersionKeepsAnEnabledSkillEnabled(): void
    {
        $this->sync($this->md('description: d', 'body'));
        $this->enable();

        $result = $this->sync($this->md('description: d', 'body'), 'sha2');

        self::assertSame(0, $result->disabledOnChange);
        self::assertTrue($this->stored()->isEnabled());
    }

    #[Test]
    public function aDescriptionOnlyChangeDisablesAnEnabledSkill(): void
    {
        $this->sync($this->md('description: d', 'body'));
        $this->enable();

        $result = $this->sync($this->md('description: changed', 'body'), 'sha2');

        self::assertSame(1, $result->disabledOnChange);
        self::assertFalse($this->stored()->isEnabled());
    }

    #[Test]
    public function aWidenedToolDeclarationDisablesAnEnabledSkill(): void
    {
        $this->sync($this->md("description: d\nallowed-tools: [GetTca]", 'body'));
        $this->enable();

        $result = $this->sync($this->md("description: d\nallowed-tools: [GetTca, DeleteRecord]", 'body'), 'sha2');

        self::assertSame(1, $result->disabledOnChange);
        self::assertFalse($this->stored()->isEnabled());
    }

    #[Test]
    public function aLegacyRowWhoseVersionIsUnchangedStaysEnabledAndGainsADigest(): void
    {
        $this->sync($this->md('description: d', 'body'));
        $this->enable(legacy: true);

        $result = $this->sync($this->md('description: d', 'body'), 'sha2');

        self::assertSame(0, $result->disabledOnChange);
        $skill = $this->stored();
        self::assertTrue($skill->isEnabled());
        self::assertSame(SkillVersionDigest::of($skill), $skill->getVersionDigest());
    }

    #[Test]
    public function aLegacyRowWhoseFrontmatterChangedIsDisabledOnItsFirstSync(): void
    {
        $this->sync($this->md("description: d\nallowed-tools: [GetTca]", 'body'));
        $this->enable(legacy: true);

        $result = $this->sync($this->md("description: d\nallowed-tools: [GetTca, DeleteRecord]", 'body'), 'sha2');

        self::assertSame(1, $result->disabledOnChange);
        self::assertFalse($this->stored()->isEnabled());
    }

    private function sync(string $markdown, string $sha = 'sha1'): SyncResult
    {
        $source = new SkillSource();
        $source->_setProperty('uid', 10);
        $source->setType(SkillSourceType::REPO->value);
        $source->setUrl('https://github.com/acme/skills');
        $source->setRef('main');

        $service = new SkillSyncService(
            new FakeGitHubClient($sha, [self::PATH], [self::PATH => $markdown]),
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->get(SkillRepository::class),
            $this->get(SkillSourceRepository::class),
            $this->get(PersistenceManagerInterface::class),
            new NullLogger(),
        );

        return $service->sync($source);
    }

    private function enable(bool $legacy = false): void
    {
        $repo  = $this->get(SkillRepository::class);
        $skill = $this->stored();
        $skill->setEnabled(true);
        if ($legacy) {
            // As every row synced before ADR-214 is stored.
            $skill->setVersionDigest('');
            $skill->setProcess(false);
        }

        $repo->update($skill);
        $this->get(PersistenceManagerInterface::class)->persistAll();
    }

    private function stored(): Skill
    {
        $this->get(PersistenceManagerInterface::class)->clearState();
        $skill = $this->get(SkillRepository::class)->findBySourceAndIdentifier(10, self::ID);
        self::assertInstanceOf(Skill::class, $skill);

        return $skill;
    }

    private function md(string $frontmatterTail, string $body): string
    {
        return "---\nname: A\n" . $frontmatterTail . "\n---\n" . $body;
    }
}
