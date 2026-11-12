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
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
        return $this->resolveRun($runUid, $this->policy->level(), 'run_privacy_level');
    }

    public function forProvider(string $providerIdentifier): PrivacyLevel
    {
        return $this->resolveProvider($providerIdentifier, $this->policy->level());
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
        $row = $queryBuilder->select(
            'run.origin',
            'provider.privacy_level',
            'run.privacy_level AS run_privacy_level',
            'run.answer_privacy_level',
        )
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
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);

        return $queryBuilder
            ->select('privacy_level')
            ->from('tx_nrllm_task')
            ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchOne();
    }

    private function resolveRun(
        int $runUid,
        PrivacyLevel $default,
        string $ceiling,
    ): PrivacyLevel {
        try {
            $level = $default;
            $row   = $this->runRow($runUid);
            if ($row === null) {
                return $level;
            }

            $level  = $this->tightened($level, $row['privacy_level'] ?? null);
            $level  = $this->tightened($level, $row[$ceiling] ?? null);
            $origin = $row['origin'] ?? null;
            if (is_string($origin) && str_starts_with($origin, self::TASK_ORIGIN_PREFIX)) {
                $level = $this->tightened(
                    $level,
                    $this->taskLevel(
                        substr($origin, strlen(self::TASK_ORIGIN_PREFIX)),
                    ),
                );
            }

            return $level;
        } catch (Throwable) {
            return PrivacyLevel::NONE;
        }
    }

    private function resolveProvider(
        string $providerIdentifier,
        PrivacyLevel $default,
    ): PrivacyLevel {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_provider');
            // A record hidden meanwhile still says what it asked for.
            $queryBuilder
                ->getRestrictions()
                ->removeByType(HiddenRestriction::class);
            $level = $queryBuilder
                ->select('privacy_level')
                ->from('tx_nrllm_provider')
                ->where(
                    $queryBuilder->expr()->eq(
                        'identifier',
                        $queryBuilder->createNamedParameter($providerIdentifier),
                    ),
                )
                ->executeQuery()
                ->fetchOne();

            return $this->tightened($default, $level);
        } catch (Throwable) {
            return PrivacyLevel::NONE;
        }
    }

    public function forRunAnswer(int $runUid): PrivacyLevel
    {
        return $this->resolveRun(
            $runUid,
            $this->answerDefault(),
            'answer_privacy_level',
        );
    }

    /**
     * The answer level a run of this configuration starts with: the answer
     * default, tightened by the level of the configuration's provider. What an
     * A2A publication profile can keep (ADR-282); a provider fallback during
     * the run can still tighten it. NONE when it cannot be read.
     */
    public function forConfigurationAnswer(int $configurationUid): PrivacyLevel
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_configuration');
            // A hidden record still counts (hidden does not switch it off);
            // a deleted one does not exist.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $level = $queryBuilder->select('provider.privacy_level')
                ->from('tx_nrllm_configuration', 'configuration')
                ->leftJoin('configuration', 'tx_nrllm_model', 'model', $queryBuilder->expr()->eq('model.uid', $queryBuilder->quoteIdentifier('configuration.model_uid')))
                ->leftJoin('model', 'tx_nrllm_provider', 'provider', $queryBuilder->expr()->eq('provider.uid', $queryBuilder->quoteIdentifier('model.provider_uid')))
                ->where($queryBuilder->expr()->eq('configuration.uid', $queryBuilder->createNamedParameter($configurationUid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();

            // No configuration row (false), or no model or provider behind it
            // (null): the level cannot be read. An empty level inherits.
            if ($level === false || $level === null) {
                return PrivacyLevel::NONE;
            }

            return $this->tightened($this->answerDefault(), $level);
        } catch (Throwable) {
            return PrivacyLevel::NONE;
        }
    }

    private function answerDefault(): PrivacyLevel
    {
        $level = $this->policy->level();

        return $level === PrivacyLevel::METADATA ? PrivacyLevel::FULL : $level;
    }
}
