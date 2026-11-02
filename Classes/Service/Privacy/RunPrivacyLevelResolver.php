<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function is_string;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The privacy level of one agent run (ADR-242): the global level, tightened by
 * the run's provider and by the task that queued it.
 *
 * A provider or a task can only tighten. An empty value follows the global
 * level, and a level looser than the global one does nothing, so nobody opens up
 * what an administrator closed by editing a record.
 *
 * A level that cannot be read is NONE: this answers what may be stored.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RunPrivacyLevelResolver implements RunPrivacyLevelsInterface
{
    private const TASK_ORIGIN_PREFIX = 'task:';

    public function __construct(
        private PrivacyPolicyInterface $policy,
        private ConnectionPool $connectionPool,
    ) {}

    public function forRun(int $runUid): PrivacyLevel
    {
        try {
            $level = $this->policy->level();
            $row   = $this->runRow($runUid);
            if ($row === null) {
                return $level;
            }

            $level  = $this->tightened($level, $row['privacy_level'] ?? null);
            $origin = $row['origin'] ?? null;
            if (is_string($origin) && str_starts_with($origin, self::TASK_ORIGIN_PREFIX)) {
                $level = $this->tightened($level, $this->taskLevel(substr($origin, strlen(self::TASK_ORIGIN_PREFIX))));
            }

            return $level;
        } catch (Throwable) {
            return PrivacyLevel::NONE;
        }
    }

    public function forProvider(string $providerIdentifier): PrivacyLevel
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_provider');
            $level        = $queryBuilder
                ->select('privacy_level')
                ->from('tx_nrllm_provider')
                ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($providerIdentifier)))
                ->executeQuery()
                ->fetchOne();

            return $this->tightened($this->policy->level(), $level);
        } catch (Throwable) {
            return PrivacyLevel::NONE;
        }
    }

    private function tightened(PrivacyLevel $level, mixed $stored): PrivacyLevel
    {
        $ceiling = is_string($stored) ? PrivacyLevel::tryFromString($stored) : null;

        return $ceiling instanceof PrivacyLevel ? PrivacyLevel::strictest($level, $ceiling) : $level;
    }

    /**
     * The run with the level of the provider its configuration names. The
     * configuration's own relation, without the restrictions of a live query: a
     * provider hidden after the run started still says what it asked for.
     *
     * @return array<string, mixed>|null
     */
    private function runRow(int $runUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_agentrun');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('run.origin', 'provider.privacy_level')
            ->from('tx_nrllm_agentrun', 'run')
            ->leftJoin('run', 'tx_nrllm_configuration', 'configuration', $queryBuilder->expr()->eq('configuration.uid', $queryBuilder->quoteIdentifier('run.configuration_uid')))
            ->leftJoin('configuration', 'tx_nrllm_model', 'model', $queryBuilder->expr()->eq('model.uid', $queryBuilder->quoteIdentifier('configuration.model_uid')))
            ->leftJoin('model', 'tx_nrllm_provider', 'provider', $queryBuilder->expr()->eq('provider.uid', $queryBuilder->quoteIdentifier('model.provider_uid')))
            ->where($queryBuilder->expr()->eq('run.uid', $queryBuilder->createNamedParameter($runUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    private function taskLevel(string $identifier): mixed
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_task');

        return $queryBuilder
            ->select('privacy_level')
            ->from('tx_nrllm_task')
            ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchOne();
    }
}
