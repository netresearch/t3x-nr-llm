<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function array_map;
use function explode;
use function in_array;
use function is_string;
use function strtolower;
use function trim;

/**
 * Reads the rule set of a configuration from the snippet library (ADR-288).
 *
 * Plain DBAL with the default restrictions, like the term lists: a hidden
 * snippet is no rule. An inactive one neither, as for composition (ADR-031).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
class RuleSetResolver
{
    private const TABLE = 'tx_nrllm_promptsnippet';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string> $tags normalized tags, see {@see \Netresearch\NrLlm\Domain\Model\LlmConfiguration::getRuleJudgeTagList()}
     */
    public function forTags(array $tags): RuleSet
    {
        if ($tags === []) {
            return new RuleSet([]);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows         = $queryBuilder
            ->select('name', 'tags', 'snippet', 'data_class')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('is_active', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $snippets = [];
        foreach ($rows as $row) {
            $own = array_map(
                static fn (string $tag): string => strtolower(trim($tag)),
                explode(',', is_string($row['tags'] ?? null) ? $row['tags'] : ''),
            );
            if (!$this->sharesTag($own, $tags)) {
                continue;
            }

            $snippets[] = [
                'name'      => is_string($row['name'] ?? null) ? $row['name'] : '',
                'text'      => is_string($row['snippet'] ?? null) ? $row['snippet'] : '',
                'dataClass' => ToolDataClass::tryFrom(is_string($row['data_class'] ?? null) ? $row['data_class'] : ''),
            ];
        }

        return RuleSet::fromSnippets($snippets);
    }

    /**
     * @param list<string> $own
     * @param list<string> $selected
     */
    private function sharesTag(array $own, array $selected): bool
    {
        foreach ($own as $tag) {
            if ($tag !== '' && in_array($tag, $selected, true)) {
                return true;
            }
        }

        return false;
    }
}
