<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillApprovalOutcome;
use Netresearch\NrLlm\Domain\Enum\SkillAuditEvent;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepository;
use Netresearch\NrLlm\Service\Skill\SkillApprovalService;
use Netresearch\NrLlm\Service\Skill\SkillAuditRepository;
use Netresearch\NrLlm\Service\Skill\SkillAuditService;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookup;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\DiffUtility;

/**
 * Approving, refusing and revoking skill versions against the database
 * (ADR-214 item 2), including what the composer then does with them.
 */
#[CoversClass(SkillApprovalService::class)]
#[CoversClass(SkillApprovalRepository::class)]
#[CoversClass(SkillSourceLookup::class)]
final class SkillApprovalServiceTest extends AbstractFunctionalTestCase
{
    private const SOURCE = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid'         => self::SOURCE,
            'title'       => 'House skills',
            'type'        => 'repo',
            'trust_level' => 'verified',
        ]);
        $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid'         => 11,
            'title'       => 'Other source',
            'type'        => 'repo',
            'trust_level' => 'first_party',
        ]);
    }

    #[Test]
    public function approvingTheVersionTheApproverSawStoresASnapshotAndMakesItAnInstruction(): void
    {
        $skill = $this->skill();

        $outcome = $this->service()->approveVersion($skill, $skill->getVersionDigest(), 1);

        self::assertSame(SkillApprovalOutcome::APPROVED, $outcome);
        $approval = (new SkillApprovalRepository($this->pool()))->findUnrevoked(5, self::SOURCE, $skill->getVersionDigest());
        self::assertNotNull($approval);
        self::assertSame('Follow the house style.', $approval->body);
        self::assertSame(['GetTca'], $approval->allowedTools);
        self::assertSame('verified', $approval->trustLevel);
        self::assertSame($skill->getVersionDigest(), SkillVersionDigest::ofFields($approval->versionFields()), 'the snapshot hashes to its digest');
        self::assertNotSame('', $this->composerFactory()->create()->composeBlock([$skill], [])->instructions);
        self::assertSame([SkillAuditEvent::VERSION_APPROVED->value], $this->auditEvents());
    }

    #[Test]
    public function anApprovalOfAVersionThatIsNoLongerCurrentIsRefusedAndAudited(): void
    {
        $skill = $this->skill();
        $seen  = $skill->getVersionDigest();
        // The sync brought a new version while the form was open.
        $skill->setBody('A different body.');
        $skill->setBodyChecksum(hash('sha256', 'A different body.'));
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        $outcome = $this->service()->approveVersion($skill, $seen, 1);

        self::assertSame(SkillApprovalOutcome::REFUSED_STALE, $outcome);
        self::assertSame([], (new SkillApprovalRepository($this->pool()))->findBySkill(5));
        self::assertSame([SkillAuditEvent::VERSION_APPROVAL_REFUSED->value], $this->auditEvents());
        self::assertSame($seen, $this->auditDigests()[0], 'the refusal names the digest the approver saw');
    }

    /**
     * The seen digest comes from a form. A value that is not a digest is
     * refused like a stale one, and the audit row stores no posted text.
     */
    #[Test]
    public function aPostedValueThatIsNotADigestIsRefusedAndNotStoredInTheAudit(): void
    {
        $skill  = $this->skill();
        $posted = str_repeat('<script>', 30);

        self::assertNotSame(SkillApprovalOutcome::APPROVED, $this->service()->approveVersion($skill, $posted, 1));
        $this->service()->revokeVersion($skill, $posted, 1);

        self::assertSame(['', ''], $this->auditDigests());
    }

    #[Test]
    public function aRecordThatFailsItsIntegrityCheckCannotBeApproved(): void
    {
        $skill = $this->skill();
        $skill->setName('Edited in the backend');

        self::assertSame(SkillApprovalOutcome::REFUSED_INTEGRITY, $this->service()->approveVersion($skill, $skill->getVersionDigest(), 1));
        self::assertSame([], (new SkillApprovalRepository($this->pool()))->findBySkill(5));
    }

    #[Test]
    public function aLegacyRowCannotBeApproved(): void
    {
        $skill = $this->skill();
        $skill->setVersionDigest('');

        self::assertSame(SkillApprovalOutcome::REFUSED_LEGACY, $this->service()->approveVersion($skill, '', 1));
    }

    #[Test]
    public function anOrphanedSkillCannotBeApproved(): void
    {
        $skill = $this->skill();
        $skill->setOrphaned(true);

        self::assertSame(SkillApprovalOutcome::REFUSED_ORPHANED, $this->service()->approveVersion($skill, $skill->getVersionDigest(), 1));
    }

    #[Test]
    public function aRevokedDigestStaysRevokedWhenTheSameBytesComeBackAndInstructsAgainOnlyAfterANewApproval(): void
    {
        $skill   = $this->skill();
        $service = $this->service();
        $service->approveVersion($skill, $skill->getVersionDigest(), 1);

        self::assertSame(1, $service->revokeVersion($skill, $skill->getVersionDigest(), 1));
        self::assertSame('', $this->composerFactory()->create()->composeBlock([$this->skill()], [])->instructions, 'a re-sync of the same bytes stays fenced');

        $service->approveVersion($skill, $skill->getVersionDigest(), 1);

        self::assertNotSame('', $this->composerFactory()->create()->composeBlock([$skill], [])->instructions);
        self::assertSame(
            [SkillAuditEvent::VERSION_APPROVED->value, SkillAuditEvent::VERSION_REVOKED->value, SkillAuditEvent::VERSION_APPROVED->value],
            $this->auditEvents(),
        );
    }

    #[Test]
    public function movingTheSkillToAnotherSourceLeavesItWithoutAMatchingApproval(): void
    {
        $skill = $this->skill();
        $this->service()->approveVersion($skill, $skill->getVersionDigest(), 1);
        $skill->setSource(11);

        self::assertSame('', $this->composerFactory()->create()->composeBlock([$skill], [])->instructions);
    }

    #[Test]
    public function theReviewDiffsTheCurrentVersionAgainstTheLatestApprovedSnapshot(): void
    {
        $skill = $this->skill();
        $this->service()->approveVersion($skill, $skill->getVersionDigest(), 1);
        $skill->setBody('Follow the <b>new</b> house style.');
        $skill->setBodyChecksum(hash('sha256', $skill->getBody()));
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        $review = $this->service()->review($skill);

        self::assertTrue($review->isApprovable());
        self::assertFalse($review->isInstruction);
        self::assertStringContainsString('<ins>', $review->bodyDiff);
        self::assertStringNotContainsString('<b>', $review->bodyDiff, 'both sides are escaped');
        self::assertCount(1, $review->history);
    }

    private function service(): SkillApprovalService
    {
        return new SkillApprovalService(
            new SkillApprovalRepository($this->pool()),
            new SkillSourceLookup($this->pool()),
            $this->composerFactory(),
            new SkillAuditService($this->auditRepository()),
            new DiffUtility(),
        );
    }

    private function composerFactory(): SkillComposerFactory
    {
        $configuration = $this->createMock(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['skills' => []]);

        return new SkillComposerFactory($configuration, new SkillApprovalRepository($this->pool()), new SkillSourceLookup($this->pool()));
    }

    private function auditRepository(): SkillAuditRepository
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => ['level' => 'full']]);

        return new SkillAuditRepository($this->pool(), new PrivacyPolicy($extensionConfiguration, new ContentRedactor()));
    }

    /**
     * @return list<string>
     */
    private function auditEvents(): array
    {
        return array_map(static fn(array $row): string => self::str($row['event'] ?? ''), $this->auditRepository()->findBySourceUid(self::SOURCE));
    }

    /**
     * @return list<string>
     */
    private function auditDigests(): array
    {
        return array_map(static fn(array $row): string => self::str($row['version_digest'] ?? ''), $this->auditRepository()->findBySourceUid(self::SOURCE));
    }

    private function skill(): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', 5);
        $skill->setSource(self::SOURCE);
        $skill->setIdentifier('10:skills/guide/SKILL.md');
        $skill->setName('Guide');
        $skill->setDescription('House style guide.');
        $skill->setBody('Follow the house style.');
        $skill->setBodyChecksum(hash('sha256', 'Follow the house style.'));
        $skill->setSupportStatus('partial');
        $skill->setAllowedTools('["GetTca"]');
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
