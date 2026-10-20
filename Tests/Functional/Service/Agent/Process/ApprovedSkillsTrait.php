<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Process;

use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepository;
use Netresearch\NrLlm\Service\Skill\SkillApprovalService;
use Netresearch\NrLlm\Service\Skill\SkillAuditRepository;
use Netresearch\NrLlm\Service\Skill\SkillAuditService;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillRecordLookup;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookup;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\DiffUtility;

/**
 * Skill rows with real approvals, for the process tests (ADR-228): a source
 * at `verified` trust, a skill row the record rule finds active, and an
 * approval of the model's current version through the approval service.
 */
trait ApprovedSkillsTrait
{
    private function seedSkillSource(int $sourceUid, string $trustLevel = 'verified'): void
    {
        $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid'         => $sourceUid,
            'title'       => 'House skills',
            'type'        => 'repo',
            'trust_level' => $trustLevel,
        ]);
    }

    /**
     * A skill row and its model, approved at its current version unless
     * `$approve` is false. Called again for the same uid, it returns a fresh
     * model of the same, already approved, row.
     */
    private function approvedSkill(int $uid, int $sourceUid, bool $process, bool $approve = true, string $allowedTools = '["get_page_content"]'): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', $uid);
        $skill->setSource($sourceUid);
        $skill->setIdentifier(sprintf('%d:skills/tour-%d/SKILL.md', $sourceUid, $uid));
        $skill->setName('Tour ' . $uid);
        $skill->setDescription('A guided tour.');
        $skill->setBody('Walk the editor through the page.');
        $skill->setBodyChecksum(hash('sha256', 'Walk the editor through the page.'));
        $skill->setSupportStatus('full');
        $skill->setAllowedTools($allowedTools);
        $skill->setProcess($process);
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        $connection = $this->pool()->getConnectionForTable('tx_nrllm_skill');
        if ($connection->count('uid', 'tx_nrllm_skill', ['uid' => $uid]) === 0) {
            $connection->insert('tx_nrllm_skill', [
                'uid'        => $uid,
                'source'     => $sourceUid,
                'identifier' => $skill->getIdentifier(),
                'enabled'    => 1,
            ]);
        }

        if ($approve && !(new SkillApprovalRepository($this->pool()))->hasUnrevokedApproval($uid, $sourceUid, $skill->getVersionDigest())) {
            $this->approvalService()->approveVersion($skill, $skill->getVersionDigest(), 1);
        }

        return $skill;
    }

    private function approvalService(): SkillApprovalService
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => ['level' => 'full']]);

        return new SkillApprovalService(
            new SkillApprovalRepository($this->pool()),
            new SkillSourceLookup($this->pool()),
            $this->composerFactory(),
            new SkillAuditService(new SkillAuditRepository($this->pool(), new PrivacyPolicy($extensionConfiguration, new ContentRedactor()))),
            new DiffUtility(),
        );
    }

    /**
     * @param array<string, string> $skills the `skills` extension settings
     */
    private function composerFactory(array $skills = []): SkillComposerFactory
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['skills' => $skills]);

        return new SkillComposerFactory($configuration, new SkillApprovalRepository($this->pool()), new SkillSourceLookup($this->pool()), new SkillRecordLookup($this->pool()));
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}
