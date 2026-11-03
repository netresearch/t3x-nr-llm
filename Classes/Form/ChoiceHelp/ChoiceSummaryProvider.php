<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\ChoiceHelp;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Describes the options of a record select: the record's description and
 * the facts its label does not show (ADR-255).
 *
 * Reads only the uids it is given — the wizard passes the options FormEngine
 * already offers, so no record the editor cannot choose reaches the page.
 * Hidden records are described too: a select keeps showing a hidden record
 * that is already chosen.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ChoiceSummaryProvider
{
    private const LABELS = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:choiceHelp.';

    /** Tables this provider knows how to describe, with the extra columns each needs. */
    private const COLUMNS = [
        'tx_nrllm_provider'      => ['adapter_type'],
        'tx_nrllm_model'         => ['provider_uid', 'model_id'],
        'tx_nrllm_configuration' => ['model_selection_mode', 'model_uid'],
        'tx_nrllm_task'          => ['configuration_uid'],
        'tx_nrllm_skill'         => ['identifier'],
    ];

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @param list<int> $uids
     *
     * @return array<int, ChoiceSummary> keyed by uid, in the order of $uids
     */
    public function summarize(string $table, array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids, static fn (int $uid): bool => $uid > 0)));
        if ($uids === [] || !isset(self::COLUMNS[$table])) {
            return [];
        }

        $rows = $this->rows($table, ['uid', 'description', ...self::COLUMNS[$table]], $uids);

        $summaries = [];
        foreach ($uids as $uid) {
            if (!isset($rows[$uid])) {
                continue;
            }

            $row             = $rows[$uid];
            $summaries[$uid] = new ChoiceSummary(
                trim($this->string($row['description'] ?? null)),
                $this->facts($table, $row),
            );
        }

        return $summaries;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function facts(string $table, array $row): array
    {
        return match ($table) {
            'tx_nrllm_provider' => $this->nonEmpty([$this->labelled('adapter', $this->string($row['adapter_type'] ?? null))]),
            'tx_nrllm_model'    => $this->nonEmpty([
                $this->labelled('provider', $this->name('tx_nrllm_provider', $this->int($row['provider_uid'] ?? null))),
                $this->labelled('modelId', $this->string($row['model_id'] ?? null)),
            ]),
            'tx_nrllm_configuration' => [$this->configurationModel($row)],
            'tx_nrllm_task'          => [$this->taskConfiguration($row)],
            'tx_nrllm_skill'         => $this->nonEmpty([$this->labelled('identifier', $this->string($row['identifier'] ?? null))]),
            default                  => [],
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function configurationModel(array $row): string
    {
        if ($this->string($row['model_selection_mode'] ?? null) === 'criteria') {
            return $this->translate('modelByCriteria', 'Picks its model by criteria when it runs');
        }

        $modelUid = $this->int($row['model_uid'] ?? null);
        $model    = $modelUid > 0 ? $this->rows('tx_nrllm_model', ['uid', 'name', 'provider_uid'], [$modelUid])[$modelUid] ?? null : null;
        if ($model === null) {
            return $this->translate('noModel', 'No model selected yet');
        }

        $name     = $this->string($model['name'] ?? null);
        $provider = $this->name('tx_nrllm_provider', $this->int($model['provider_uid'] ?? null));

        return $this->labelled('model', $provider !== '' ? $name . ' (' . $provider . ')' : $name);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function taskConfiguration(array $row): string
    {
        $name = $this->name('tx_nrllm_configuration', $this->int($row['configuration_uid'] ?? null));

        return $this->labelled(
            'configuration',
            $name !== '' ? $name : $this->translate('defaultConfiguration', 'the default configuration'),
        );
    }

    private function name(string $table, int $uid): string
    {
        if ($uid <= 0) {
            return '';
        }

        return $this->string($this->rows($table, ['uid', 'name'], [$uid])[$uid]['name'] ?? null);
    }

    /**
     * @param list<string> $columns
     * @param list<int>    $uids
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $table, array $columns, array $uids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $result = $queryBuilder
            ->select(...$columns)
            ->from($table)
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery();

        $rows = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $rows[$this->int($row['uid'] ?? null)] = $row;
        }

        return $rows;
    }

    private function labelled(string $key, string $value): string
    {
        if ($value === '') {
            return '';
        }

        $fallbacks = [
            'adapter'       => 'Adapter: %s',
            'provider'      => 'Provider: %s',
            'modelId'       => 'Model ID: %s',
            'model'         => 'Model: %s',
            'configuration' => 'Configuration: %s',
            'identifier'    => 'Identifier: %s',
        ];

        return sprintf($this->translate($key, $fallbacks[$key] ?? '%s'), $value);
    }

    private function translate(string $key, string $fallback): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!is_object($languageService) || !method_exists($languageService, 'sL')) {
            return $fallback;
        }

        $translated = $languageService->sL(self::LABELS . $key);

        return is_string($translated) && $translated !== '' ? $translated : $fallback;
    }

    /**
     * @param list<string> $facts
     *
     * @return list<string>
     */
    private function nonEmpty(array $facts): array
    {
        return array_values(array_filter($facts, static fn (string $fact): bool => $fact !== ''));
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
