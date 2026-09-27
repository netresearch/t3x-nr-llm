<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Schema;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Schema\SearchableSchemaFieldsCollector;

/**
 * The backend search scope of each nr_llm table on the running TYPO3 version.
 *
 * TYPO3 v13 derives it from `ctrl.searchFields` (set by
 * Configuration/TCA/Overrides/v13_search_fields.php), TYPO3 v14 from the
 * per-column `searchable` flag. The core collector answers for both, and it is
 * also what the search_records tool reads, so a column that becomes searchable
 * here becomes visible to the LLM there. Select and user columns were listed in
 * `searchFields` but are not searchable on either version.
 */
#[CoversNothing]
final class SearchScopeTest extends AbstractFunctionalTestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function tableProvider(): array
    {
        return [
            'configuration' => ['tx_nrllm_configuration', ['description', 'identifier', 'name']],
            'glossary'      => ['tx_nrllm_glossary', ['entries', 'name']],
            'mcp_server'    => ['tx_nrllm_mcp_server', ['description', 'identifier', 'name', 'url']],
            'model'         => ['tx_nrllm_model', ['description', 'identifier', 'name']],
            'promptsnippet' => ['tx_nrllm_promptsnippet', ['description', 'identifier', 'name', 'snippet', 'tags']],
            'provider'      => ['tx_nrllm_provider', ['description', 'identifier', 'name']],
            'skill'         => ['tx_nrllm_skill', ['description', 'identifier', 'name']],
            'skill_source'  => ['tx_nrllm_skill_source', ['ref', 'title', 'url']],
            'task'          => ['tx_nrllm_task', ['description', 'identifier', 'name']],
            'user_budget'   => ['tx_nrllm_user_budget', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('tableProvider')]
    public function searchableFieldsAreUnchanged(string $table, array $expected): void
    {
        $collector = $this->get(SearchableSchemaFieldsCollector::class);
        self::assertInstanceOf(SearchableSchemaFieldsCollector::class, $collector);

        $fields = $collector->getFieldNames($table);
        sort($fields);

        self::assertSame($expected, $fields);
    }
}
