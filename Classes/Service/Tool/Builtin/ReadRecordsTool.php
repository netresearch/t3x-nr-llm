<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Generic filtered read of one TCA table — never raw SQL.
 *
 * Inspired by the ReadTable tool of EXT:mcp_server and the typo3-records
 * tool of EXT:typo3_ai_mate (both GPL-2.0-or-later); own implementation.
 * Filters are equality-only (`uid`, `pid`, `where_equals`) and bound as
 * named parameters; the selectable fields are validated against the TCA
 * column set.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): table access is
 * gated through {@see TableReadAccessService} (sensitive-table denylist for
 * everyone; `tables_select` + `adminOnly` for non-admins, fail-closed
 * without a backend user); credential-ish columns are silently dropped from
 * every field list and filter for every user; default query restrictions
 * keep deleted/hidden/timed rows out; and for non-admins each row's page is
 * checked against the acting user's PAGE_SHOW permission before it egresses.
 */
final readonly class ReadRecordsTool implements ToolInterface
{
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Table not found or not permitted.';

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    private const MAX_OFFSET = 10000;

    private const MAX_FILTERS = 5;

    /** Per-field value cap so a single record cannot flood the egress. */
    private const VALUE_CAP = 300;

    public function __construct(
        protected ConnectionPool $connectionPool,
        protected TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'read_records',
            'Read records of one TYPO3 table with equality filters (no SQL). Returns uid, pid and the '
            . 'label field by default; pass "fields" for specific columns. Deleted and hidden records '
            . 'are excluded; credential-like columns are never returned.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'The TCA table to read (e.g. "pages", "tt_content").',
                    ],
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'Optional: a single record uid.',
                    ],
                    'pid' => [
                        'type'        => 'integer',
                        'description' => 'Optional: only records on this page uid.',
                    ],
                    'where_equals' => [
                        'type'        => 'object',
                        'description' => 'Optional: field => value equality filters (max 5, TCA columns only).',
                        'additionalProperties' => true,
                    ],
                    'fields' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Optional: columns to return (validated against the TCA).',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum rows (default 20, hard cap 50).',
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Rows to skip for pagination (default 0).',
                    ],
                ],
                'required' => ['table'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if ($user === null) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $table = trim(self::toStr($arguments['table'] ?? ''));
        if (!$this->tableAccess->canReadTable($user, $table)) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $columns = $this->tcaColumns($table);

        $fields = $this->resolveFields($table, $columns, $arguments['fields'] ?? null);
        if ($fields === []) {
            return ToolResult::text('No readable fields.');
        }

        $filters = $this->resolveFilters($columns, $arguments);
        if ($filters === null) {
            return ToolResult::text('Invalid filter: only existing, non-credential TCA columns with scalar values are allowed.');
        }

        // A non-admin explicitly filtering by a language they may not access
        // gets nothing — the backend language restriction applies here too.
        if (!$user->isAdmin()
            && isset($filters['sys_language_uid'])
            && !$user->checkLanguageAccess((int)$filters['sys_language_uid'])
        ) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $limit = self::toInt($arguments['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);

        $offset = self::toInt($arguments['offset'] ?? 0);
        $offset = max(0, min($offset, self::MAX_OFFSET));

        // For a non-admin on a language-aware table, fetch the language column
        // too (even if not displayed) so rows in a forbidden language can be
        // dropped below — an unfiltered read must not leak them to the provider.
        $languageField = $this->languageField($table);
        $queryFields   = $fields;
        if (!$user->isAdmin() && $languageField !== null && !in_array($languageField, $queryFields, true)) {
            $queryFields[] = $languageField;
        }

        $rows = $this->fetchRows($table, $queryFields, $filters, $limit, $offset);

        // Non-admins only see rows on pages they may show and in languages they
        // may access (fail-closed).
        if (!$user->isAdmin()) {
            $permsClause = self::toStr($user->getPagePermsClause(Permission::PAGE_SHOW));
            $pidAccess   = [];
            $rows        = array_values(array_filter(
                $rows,
                fn(array $row): bool => $this->pageIsReadable($table, $row, $permsClause, $pidAccess)
                    && ($languageField === null || $user->checkLanguageAccess(self::toInt($row[$languageField] ?? 0))),
            ));
        }

        if ($rows === []) {
            return ToolResult::text(sprintf('No records in %s for the given filters.', $table));
        }

        // Build the human-readable text and the structured TABLE artifact in ONE
        // pass from the SAME redacted/formatted cells, so the artifact can never
        // drift from — or re-expose more than — the text egress (ADR-108). The
        // artifact is run-only; only the text `content` reaches the provider.
        $lines        = [sprintf('Records in %s (%d, offset %d):', $table, count($rows), $offset)];
        $artifactRows = [];
        foreach ($rows as $row) {
            $lines[]     = sprintf('- %s:%d', $table, self::toInt($row['uid'] ?? 0));
            $artifactRow = [];
            foreach ($fields as $field) {
                $value         = $this->formatValue($row[$field] ?? null);
                $artifactRow[] = $value;
                if ($field !== 'uid') {
                    $lines[] = sprintf('  %s: %s', $field, $value);
                }
            }
            $artifactRows[] = $artifactRow;
        }

        return ToolResult::text(
            implode("\n", $lines),
            new ToolArtifact(ArtifactType::TABLE, sprintf('%s records', $table), [
                'columns' => $fields,
                'rows'    => $artifactRows,
            ]),
        );
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
     * The table's TCA language field (ctrl.languageField), or null when the
     * table is not language-aware.
     */
    private function languageField(string $table): ?string
    {
        $tca        = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $definition = $tca[$table] ?? null;
        $ctrl       = is_array($definition) ? ($definition['ctrl'] ?? null) : null;
        $field      = is_array($ctrl) ? ($ctrl['languageField'] ?? null) : null;

        return is_string($field) && $field !== '' ? $field : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function tcaColumns(string $table): array
    {
        $tca        = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $definition = $tca[$table] ?? null;
        $columns    = is_array($definition) ? ($definition['columns'] ?? null) : null;

        return is_array($columns) ? $columns : [];
    }

    /**
     * The validated select list: the caller's fields (existing, non-sensitive
     * TCA columns plus uid/pid), or a compact default of uid, pid and the
     * label field(s).
     *
     * @param array<array-key, mixed> $columns
     *
     * @return list<string>
     */
    private function resolveFields(string $table, array $columns, mixed $requested): array
    {
        $fields = [];
        if (is_array($requested) && $requested !== []) {
            foreach ($requested as $field) {
                $field = trim(self::toStr($field));
                if ($field === '' || $this->tableAccess->isSensitiveField($field)) {
                    continue;
                }
                if ($field !== 'uid' && $field !== 'pid' && !isset($columns[$field])) {
                    continue;
                }
                $fields[] = $field;
            }
        } else {
            $tca        = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
            $definition = is_array($tca[$table] ?? null) ? $tca[$table] : [];
            $ctrl       = is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
            $label = self::toStr($ctrl['label'] ?? '');
            if ($label !== '' && isset($columns[$label]) && !$this->tableAccess->isSensitiveField($label)) {
                $fields[] = $label;
            }
            foreach (explode(',', self::toStr($ctrl['label_alt'] ?? '')) as $alt) {
                $alt = trim($alt);
                if ($alt !== '' && isset($columns[$alt]) && !$this->tableAccess->isSensitiveField($alt)) {
                    $fields[] = $alt;
                }
            }
        }

        if ($fields === []) {
            return [];
        }

        return array_values(array_unique(array_merge(['uid', 'pid'], $fields)));
    }

    /**
     * The validated equality filters (uid/pid arguments folded in), or null
     * when any filter names a non-existent or credential-ish column or holds
     * a non-scalar value.
     *
     * @param array<array-key, mixed> $columns
     * @param array<string, mixed>    $arguments
     *
     * @return array<string, int|string>|null
     */
    private function resolveFilters(array $columns, array $arguments): ?array
    {
        $filters = [];

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid > 0) {
            $filters['uid'] = $uid;
        }

        $pid = self::toInt($arguments['pid'] ?? -1);
        if ($pid >= 0 && isset($arguments['pid'])) {
            $filters['pid'] = $pid;
        }

        $whereEquals = $arguments['where_equals'] ?? null;
        if (is_array($whereEquals)) {
            $count = 0;
            foreach ($whereEquals as $field => $value) {
                if (++$count > self::MAX_FILTERS) {
                    return null;
                }
                $field = trim(self::toStr($field));
                if ($field === '' || $this->tableAccess->isSensitiveField($field)) {
                    return null;
                }
                if ($field !== 'uid' && $field !== 'pid' && !isset($columns[$field])) {
                    return null;
                }
                if (!is_scalar($value)) {
                    return null;
                }
                $filters[$field] = is_int($value) ? $value : self::toStr($value);
            }
        }

        return $filters;
    }

    /**
     * @param list<string>              $fields
     * @param array<string, int|string> $filters
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $table, array $fields, array $filters, int $limit, int $offset): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // A tool result is sent verbatim to an external LLM provider, so exclude
        // workspace draft/versioned rows (the default restrictions do not) — the
        // provider must never see unpublished content. Mirrors DatabaseSearchBackend.
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));
        $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters as $field => $value) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $field,
                    is_int($value)
                        ? $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)
                        : $queryBuilder->createNamedParameter($value),
                ),
            );
        }

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * Whether the acting non-admin user may show the page a row lives on
     * (the row itself for `pages`, the parent page otherwise), memoised per
     * page uid. Root-level rows (pid 0) of non-pages tables fail closed for
     * non-admins.
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

        if (!isset($cache[$pageUid])) {
            $cache[$pageUid] = is_array(BackendUtility::readPageAccess($pageUid, $permsClause));
        }

        return $cache[$pageUid];
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = trim((string)preg_replace('/\s+/', ' ', self::toStr($value)));
        if (mb_strlen($string) > self::VALUE_CAP) {
            return mb_substr($string, 0, self::VALUE_CAP) . '…';
        }

        return $string;
    }

    public function getGroup(): string
    {
        return 'content';
    }
}
