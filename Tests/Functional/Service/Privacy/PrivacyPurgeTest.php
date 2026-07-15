<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Privacy;

use Netresearch\NrLlm\Command\PurgePrivacyDataCommand;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepository;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Service\Skill\SkillAuditRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional coverage for the centralised retention purge (ADR-064): each
 * content repository deletes rows older than the cutoff and keeps fresh ones,
 * and the purge command wires from the container with all its dependencies.
 */
#[CoversClass(EvaluationResultRepository::class)]
#[CoversClass(SkillAuditRepository::class)]
#[CoversClass(PurgePrivacyDataCommand::class)]
final class PrivacyPurgeTest extends AbstractFunctionalTestCase
{
    private const EVAL_TABLE  = 'tx_nrllm_eval_result';
    private const AUDIT_TABLE = 'tx_nrllm_skill_audit';

    #[Test]
    public function purgeOlderThanDeletesOnlyOldEvaluationResults(): void
    {
        $connectionPool = $this->connectionPool();
        $repository     = new EvaluationResultRepository($connectionPool, $this->fullPolicy());
        $connection     = $connectionPool->getConnectionForTable(self::EVAL_TABLE);

        $tenDaysAgo = time() - (10 * 86400);
        $connection->insert(self::EVAL_TABLE, $this->evalRow('old.set', $tenDaysAgo));
        $connection->insert(self::EVAL_TABLE, $this->evalRow('fresh.set', time()));

        $deleted = $repository->purgeOlderThan(time() - (5 * 86400));

        self::assertSame(1, $deleted);
        self::assertSame(0, $connection->count('*', self::EVAL_TABLE, ['set_identifier' => 'old.set']));
        self::assertSame(1, $connection->count('*', self::EVAL_TABLE, ['set_identifier' => 'fresh.set']));
    }

    #[Test]
    public function purgeOlderThanDeletesOnlyOldSkillAuditRows(): void
    {
        $connectionPool = $this->connectionPool();
        $repository     = new SkillAuditRepository($connectionPool, $this->fullPolicy());
        $connection     = $connectionPool->getConnectionForTable(self::AUDIT_TABLE);

        $tenDaysAgo = time() - (10 * 86400);
        $connection->insert(self::AUDIT_TABLE, $this->auditRow('old', $tenDaysAgo));
        $connection->insert(self::AUDIT_TABLE, $this->auditRow('fresh', time()));

        $deleted = $repository->purgeOlderThan(time() - (5 * 86400));

        self::assertSame(1, $deleted);
        self::assertSame(0, $connection->count('*', self::AUDIT_TABLE, ['skill_identifier' => 'old']));
        self::assertSame(1, $connection->count('*', self::AUDIT_TABLE, ['skill_identifier' => 'fresh']));
    }

    #[Test]
    public function purgeCommandResolvesFromContainerWithAllDependencies(): void
    {
        // Proves the command self-registers (autoconfigure + #[AsCommand]) and
        // that all four injected interfaces resolve through the container.
        $command = $this->get(PurgePrivacyDataCommand::class);

        self::assertInstanceOf(PurgePrivacyDataCommand::class, $command);
        self::assertSame('nrllm:privacy:purge', $command->getName());
    }

    /**
     * @return array<string, int|string>
     */
    private function evalRow(string $setIdentifier, int $runDate): array
    {
        return [
            'pid'            => 0,
            'set_identifier' => $setIdentifier,
            'model_id'       => 'm',
            'grader'         => 'deterministic',
            'prompt_count'   => 1,
            'passed_count'   => 1,
            'pass_rate'      => 1,
            'mean_score'     => 1,
            'details'        => '[]',
            'run_date'       => $runDate,
            'tstamp'         => $runDate,
            'crdate'         => $runDate,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function auditRow(string $skillIdentifier, int $crdate): array
    {
        return [
            'pid'              => 0,
            'crdate'           => $crdate,
            'event'            => 'ingest_created',
            'source_uid'       => 1,
            'skill_identifier' => $skillIdentifier,
            'source_sha'       => '',
            'body_checksum'    => '',
            'trust_level'      => 'verified',
            'scan_result'      => '',
            'actor_uid'        => 0,
            'detail'           => '',
        ];
    }

    private function fullPolicy(): PrivacyPolicy
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => ['level' => 'full']]);

        return new PrivacyPolicy($extensionConfiguration, new ContentRedactor());
    }

    private function connectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        return $connectionPool;
    }
}
