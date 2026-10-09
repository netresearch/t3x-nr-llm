<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillApprovalOutcome;
use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepository;
use Netresearch\NrLlm\Service\Skill\SkillApprovalService;
use Netresearch\NrLlm\Service\Skill\SkillAuditRepository;
use Netresearch\NrLlm\Service\Skill\SkillAuditService;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillDiscovery;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookup;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Skill\Fixtures\FakeGitHubClient;
use Netresearch\NrLlm\Updates\SkillVersionDigestUpdateWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The backend as a skill source (ADR-214 item 3), against the database.
 */
#[CoversClass(SkillSyncService::class)]
#[CoversClass(SkillApprovalService::class)]
#[CoversClass(SkillVersionDigestUpdateWizard::class)]
final class BackendSkillSourceTest extends AbstractFunctionalTestCase
{
    private const SOURCE = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid'         => self::SOURCE,
            'title'       => 'Written here',
            'type'        => 'backend',
            'trust_level' => 'verified',
        ]);
        $this->pool()->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid'            => 7,
            'pid'            => 0,
            'source'         => self::SOURCE,
            'identifier'     => 'seo-tour',
            'name'           => 'SEO tour',
            'description'    => 'Optimise one page.',
            'body'           => 'Pick a page, then work through the findings.',
            'support_status' => 'full',
            'enabled'        => 1,
            'version_digest' => '',
        ]);
    }

    #[Test]
    public function syncingABackendSourceIsRefusedAndOrphansNothing(): void
    {
        $source = new SkillSource();
        $source->_setProperty('uid', self::SOURCE);
        $source->setType(SkillSourceType::BACKEND->value);

        $result = $this->syncService()->sync($source);

        self::assertSame(SyncStatus::ERROR, $result->status);
        self::assertStringContainsString('nothing to sync', implode(' ', $result->errors));
        self::assertSame(0, $result->orphaned);
        $source = $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->select(['sync_status', 'last_synced'], 'tx_nrllm_skill_source', ['uid' => self::SOURCE])->fetchAssociative();
        self::assertIsArray($source);
        self::assertSame('never_synced', $source['sync_status'], 'refused before the lock: the source row is untouched');
        self::assertSame(0, (int)$source['last_synced']);
        $row = $this->pool()->getConnectionForTable('tx_nrllm_skill')->select(['orphaned', 'enabled'], 'tx_nrllm_skill', ['uid' => 7])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(0, (int)$row['orphaned']);
        self::assertSame(1, (int)$row['enabled']);
    }

    #[Test]
    public function theDigestWizardLeavesBackendSkillsAlone(): void
    {
        $wizard = new SkillVersionDigestUpdateWizard(
            $this->pool(),
            new SkillMarkdownParser(),
            new SkillAuditService($this->auditRepository()),
        );

        self::assertFalse($wizard->updateNecessary(), 'a backend skill without a stored digest is not a legacy row');
        $wizard->executeUpdate();

        $row = $this->pool()->getConnectionForTable('tx_nrllm_skill')->select(['enabled', 'version_digest'], 'tx_nrllm_skill', ['uid' => 7])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(1, (int)$row['enabled']);
        self::assertSame('', $row['version_digest']);
    }

    #[Test]
    public function aBackendVersionIsApprovedByItsComputedDigestAndAnEditNeedsANewApproval(): void
    {
        $skill  = $this->skill();
        $digest = SkillVersionDigest::of($skill);

        self::assertSame($digest, $this->approvalService()->review($skill)->currentDigest);
        self::assertSame(SkillApprovalOutcome::APPROVED, $this->approvalService()->approveVersion($skill, $digest, 1));
        self::assertNotSame('', $this->composerFactory()->create()->composeBlock([$skill], [])->instructions);

        $skill->setBody('Edited after the approval.');

        self::assertSame('', $this->composerFactory()->create()->composeBlock([$skill], [])->instructions);
        self::assertSame(SkillApprovalOutcome::REFUSED_STALE, $this->approvalService()->approveVersion($skill, $digest, 1));
        self::assertTrue($this->approvalService()->review($skill)->isApprovable());
    }

    /**
     * The approved tool declaration belongs to the approval's source: moving
     * the skill to another backend source leaves it granting nothing.
     */
    #[Test]
    public function anApprovedDeclarationDoesNotFollowTheSkillToAnotherSource(): void
    {
        $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid'         => self::SOURCE + 1,
            'title'       => 'Also written here',
            'type'        => 'backend',
            'trust_level' => 'verified',
        ]);
        $skill = $this->skill();
        $skill->setAllowedTools('["get_page"]');
        self::assertSame(SkillApprovalOutcome::APPROVED, $this->approvalService()->approveVersion($skill, SkillVersionDigest::of($skill), 1));

        self::assertSame(['get_page'], $this->composerFactory()->create()->declaredTools($skill));

        $skill->setSource(self::SOURCE + 1);

        self::assertSame([], $this->composerFactory()->create()->declaredTools($skill));
    }

    /**
     * A record that carries what a sync wrote is checked against it on any
     * source: moved onto a backend source and edited, it is an integrity
     * failure, not a new version anyone could approve.
     */
    #[Test]
    public function aSyncedSkillMovedOntoTheBackendSourceCannotBeApprovedAfterAnEdit(): void
    {
        $body = 'Pick a page, then work through the findings.';
        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['body_checksum' => hash('sha256', $body)], ['uid' => 7]);
        $skill = $this->skill();
        $skill->setVersionDigest(SkillVersionDigest::of($skill));
        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['version_digest' => $skill->getVersionDigest()], ['uid' => 7]);

        $skill = $this->skill();
        $skill->setBody('Text written after the move.');

        self::assertSame(SkillApprovalOutcome::REFUSED_INTEGRITY, $this->approvalService()->approveVersion($skill, SkillVersionDigest::of($skill), 1));
    }

    private function skill(): Skill
    {
        $this->get(PersistenceManagerInterface::class)->clearState();
        $skill = $this->get(SkillRepository::class)->findByUid(7);
        self::assertInstanceOf(Skill::class, $skill);

        return $skill;
    }

    private function syncService(): SkillSyncService
    {
        return new SkillSyncService(
            new FakeGitHubClient(),
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->get(SkillRepository::class),
            $this->get(SkillSourceRepository::class),
            $this->get(PersistenceManagerInterface::class),
            new NullLogger(),
        );
    }

    private function approvalService(): SkillApprovalService
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
        $configuration->method('get')->willReturn(['skills' => ['minTrustLevel' => 'verified']]);

        return new SkillComposerFactory($configuration, new SkillApprovalRepository($this->pool()), new SkillSourceLookup($this->pool()));
    }

    private function auditRepository(): SkillAuditRepository
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => ['level' => 'full']]);

        return new SkillAuditRepository($this->pool(), new PrivacyPolicy($extensionConfiguration, new ContentRedactor()));
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}
