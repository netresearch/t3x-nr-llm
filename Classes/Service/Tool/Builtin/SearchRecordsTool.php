<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Schema\SearchableSchemaFieldsCollector;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Full-text search across the tables that declare TCA `searchFields`.
 *
 * Inspired by the Search tool of EXT:mcp_server (hauptsache.net,
 * GPL-2.0-or-later); own implementation. Per table a LIKE query runs across
 * the declared search fields and returns compact `table:uid` hits with a
 * short excerpt around the first matching field.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): the table set is
 * gated through {@see TableReadAccessService} (sensitive-table denylist for
 * everyone; `tables_select` + `adminOnly` for non-admins, fail-closed
 * without a backend user), credential-ish columns are dropped from the
 * search-field lists, default query restrictions keep deleted/hidden/timed
 * rows out for every user, and for non-admins each hit's page is checked
 * against the acting user's PAGE_SHOW permission (memoised per pid) before
 * it egresses.
 */
final readonly class SearchRecordsTool implements ToolInterface
{
    use SafeCastTrait;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    private const MIN_QUERY_LENGTH = 2;

    private const MAX_QUERY_LENGTH = 100;

    private const EXCERPT_LENGTH = 120;

    /**
     * The '<' of non-inline tags only: a space inserted there keeps adjacent
     * text nodes ("<td>Price</td><td>100</td>") separated after strip_tags
     * without splitting words joined by inline markup ("cyber<b>security</b>").
     */
    private const NON_INLINE_TAG_PATTERN = '/<(?!\/?(?:a|abbr|b|bdi|bdo|cite|code|data|dfn|em|i|kbd|mark|q|s|samp|small|span|strong|sub|sup|time|u|var|wbr)\b)/i';

    public function __construct(
        private ConnectionPool $connectionPool,
        private TableReadAccessService $tableAccess,
        private SearchableSchemaFieldsCollector $searchableFields,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'search_records',
            'Full-text search across the TYPO3 tables that define TCA searchFields (pages, content '
            . 'elements, ...). Returns table:uid hits with a short excerpt around the match. A hit on a '
            . 'language-aware table names its language and, for a translation, the uid of the record it '
            . 'translates — a translation is not a duplicate. Deleted and hidden records are excluded.',
            [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'The text to search for (2-100 characters).',
                    ],
                    'table' => [
                        'type'        => 'string',
                        'description' => 'Optional: restrict the search to one table (e.g. "tt_content").',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum total hits (default 20, hard cap 50).',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::text('Not permitted.');
        }

        $query = trim(self::toStr($arguments['query'] ?? ''));
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return ToolResult::text('Query too short (minimum 2 characters).');
        }

        $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);

        $limit = self::toInt($arguments['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $limit = min($limit, self::MAX_LIMIT);

        $restrictTable = trim(self::toStr($arguments['table'] ?? ''));
        $tables        = $this->searchableTables($restrictTable, $context);
        if ($tables === []) {
            return ToolResult::text($restrictTable !== ''
                ? 'Table not found or not permitted.'
                : 'No searchable tables available.');
        }

        $isAdmin     = $user->isAdmin();
        $permsClause = $isAdmin ? '' : self::toStr($user->getPagePermsClause(Permission::PAGE_SHOW));
        $pidAccess   = [];

        $lines     = [];
        $remaining = $limit;
        foreach ($tables as $table => $searchFields) {
            if ($remaining < 1) {
                break;
            }

            $languageField = $this->languageFields($table)[0];
            foreach ($this->searchTable($table, $searchFields, $query, $remaining, $isAdmin ? null : $this->allowedLanguages($user, $languageField)) as $row) {
                // Non-admins only see hits on pages they may show (fail-closed).
                if (!$isAdmin && !$this->pageIsReadable($table, $row, $permsClause, $pidAccess)) {
                    continue;
                }

                // ...and in languages they may access, as read_records already
                // enforces. The language column is selected for the hit line
                // anyway (NEXT-167); a hit in a forbidden language must not
                // reach the provider through the search either.
                if (!$isAdmin && $languageField !== null && !$user->checkLanguageAccess(self::toInt($row[$languageField] ?? 0))) {
                    continue;
                }

                $lines[] = $this->formatHit($table, $row, $searchFields, $query);
                --$remaining;
                if ($remaining < 1) {
                    break;
                }
            }
        }

        if ($lines === []) {
            return ToolResult::text('No matches.');
        }

        array_unshift($lines, sprintf('Matches for "%s" (%d):', $query, count($lines)));

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins; execute() self-enforces the acting user's TYPO3 permissions.
        return false;
    }

    /**
     * The tables this call may search, mapped to their searchable fields as
     * resolved by the core's {@see SearchableSchemaFieldsCollector} (the
     * cross-version source: `ctrl.searchFields` on v13, the per-column
     * `searchable` flag on v14), minus credential-ish columns.
     *
     * @return array<string, list<string>>
     */
    private function searchableTables(string $restrictTable, ToolExecutionContext $context): array
    {
        $user   = $context->actingBackendUser();
        $tca    = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $result = [];

        foreach (array_keys($tca) as $table) {
            $table = (string)$table;
            if ($restrictTable !== '' && $table !== $restrictTable) {
                continue;
            }

            if (!$this->tableAccess->canReadTable($user, $table)) {
                continue;
            }

            $fields = array_values(array_filter(
                $this->searchableFields->getFieldNames($table),
                fn(string $field): bool => !$this->tableAccess->isSensitiveField($field),
            ));

            if ($fields !== []) {
                $result[$table] = $fields;
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * LIKE across the table's search fields with default restrictions
     * (deleted/hidden/timed rows excluded for every user).
     *
     * @param list<string>                        $searchFields
     * @param array{0: string, 1: list<int>}|null $languages    the language column and the languages the acting
     *                                                          user may read; null when the rows are not restricted
     *
     * @return list<array<string, mixed>>
     */
    private function searchTable(string $table, array $searchFields, string $query, int $limit, ?array $languages = null): array
    {
        $labelField   = $this->labelField($table);
        $selectFields = array_values(array_unique(array_merge(
            ['uid', 'pid'],
            $labelField !== '' ? [$labelField] : [],
            array_values(array_filter($this->languageFields($table))),
            $searchFields,
        )));

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // A tool result is sent verbatim to an external LLM provider, so exclude
        // workspace draft/versioned rows (the default restrictions do not) — the
        // provider must never see unpublished content. Mirrors DatabaseSearchBackend.
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));
        $like = '%' . $queryBuilder->escapeLikeWildcards($query) . '%';

        $orConditions = [];
        foreach ($searchFields as $field) {
            $orConditions[] = $queryBuilder->expr()->like(
                $field,
                $queryBuilder->createNamedParameter($like),
            );
        }

        $queryBuilder
            ->select(...$selectFields)
            ->from($table)
            ->where($queryBuilder->expr()->or(...$orConditions));

        // In the query, not only in the loop after it: the limit applies here,
        // and rows in a forbidden language would otherwise use it up and leave
        // permitted matches unfetched.
        if ($languages !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->in(
                $languages[0],
                $queryBuilder->createNamedParameter($languages[1], Connection::PARAM_INT_ARRAY),
            ));
        }

        $rows = $queryBuilder
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values($rows);
    }

    /**
     * Whether the acting non-admin user may show the page a hit lives on
     * (the row itself for `pages` hits, the parent page otherwise), memoised
     * per page uid. Unresolvable pages fail closed.
     *
     * @param array<string, mixed> $row
     * @param array<int, bool>     $cache
     */
    private function pageIsReadable(string $table, array $row, string $permsClause, array &$cache): bool
    {
        $pageUid = $table === 'pages' ? self::toInt($row['uid'] ?? 0) : self::toInt($row['pid'] ?? 0);
        if ($pageUid < 1) {
            return false;
        }

        $cache[$pageUid] ??= is_array(BackendUtility::readPageAccess($pageUid, $permsClause));

        return $cache[$pageUid];
    }

    /**
     * One compact hit line plus a match excerpt: `table:uid · label · pid N`.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $searchFields
     */
    private function formatHit(string $table, array $row, array $searchFields, string $query): string
    {
        $labelField = $this->labelField($table);
        $label      = $labelField !== '' ? self::toStr($row[$labelField] ?? '') : '';

        $line = sprintf(
            '%s:%d · %s · pid %d',
            $table,
            self::toInt($row['uid'] ?? 0),
            $label !== '' ? $label : '(no label)',
            self::toInt($row['pid'] ?? 0),
        );

        [$languageField, $parentField] = $this->languageFields($table);
        if ($languageField !== null) {
            $line .= sprintf(' · language %d', self::toInt($row[$languageField] ?? 0));
        }

        $parent = $parentField !== null ? self::toInt($row[$parentField] ?? 0) : 0;
        if ($parent > 0) {
            $line .= sprintf(' · translation of %s:%d', $table, $parent);
        }

        foreach ($searchFields as $field) {
            $value = self::toStr($row[$field] ?? '');
            if ($value === '') {
                continue;
            }

            // Space before each non-inline tag so adjacent text nodes stay
            // separated after strip_tags; the collapse removes the extra spaces.
            $spaced   = (string)preg_replace(self::NON_INLINE_TAG_PATTERN, ' <', $value);
            $plain    = trim((string)preg_replace('/\s+/', ' ', strip_tags($spaced)));
            $position = mb_stripos($plain, $query);
            if ($position === false) {
                continue;
            }

            $start   = max(0, $position - self::EXCERPT_LENGTH / 2);
            $excerpt = mb_substr($plain, $start, self::EXCERPT_LENGTH);

            return $line . "\n" . sprintf(
                '  match(%s): %s%s%s',
                $field,
                $start > 0 ? '…' : '',
                $excerpt,
                mb_strlen($plain) > $start + self::EXCERPT_LENGTH ? '…' : '',
            );
        }

        return $line;
    }

    /**
     * The language column and the languages the acting user may read, or null
     * when every language is readable (the table has no language column, or
     * the user's language list is empty — core's checkLanguageAccess() reads
     * an empty list as "all"). -1, "all languages", is always readable.
     *
     * @return array{0: string, 1: list<int>}|null
     */
    private function allowedLanguages(BackendUserAuthentication $user, ?string $languageField): ?array
    {
        $allowed = trim(self::toStr($user->groupData['allowed_languages'] ?? ''));
        if ($languageField === null || $allowed === '') {
            return null;
        }

        $languages = array_map(static fn(string $id): int => (int)$id, GeneralUtility::trimExplode(',', $allowed, true));
        $languages[] = -1;

        return [$languageField, array_values(array_unique($languages))];
    }

    /**
     * The table's language field and translation-parent field, each null when
     * the table does not declare it (NEXT-167: a page and its translation are
     * two hits with the same title, and without these they read as duplicates).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function languageFields(string $table): array
    {
        $tca        = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $definition = is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $ctrl       = is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
        $columns    = is_array($definition['columns'] ?? null) ? $definition['columns'] : [];

        $fields = [];
        foreach (['languageField', 'transOrigPointerField'] as $key) {
            $field    = $ctrl[$key] ?? null;
            $fields[] = is_string($field) && $field !== '' && isset($columns[$field]) ? $field : null;
        }

        return [$fields[0], $fields[1]];
    }

    private function labelField(string $table): string
    {
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $definition = $tca[$table] ?? null;
        $ctrl  = is_array($definition) ? ($definition['ctrl'] ?? null) : null;
        $label = is_array($ctrl) ? self::toStr($ctrl['label'] ?? '') : '';

        // A customised ctrl.label could point at a credential-ish column —
        // the field denylist applies to the label too (drop, don't leak).
        return $this->tableAccess->isSensitiveField($label) ? '' : $label;
    }

    public function getGroup(): string
    {
        return 'content';
    }
}
