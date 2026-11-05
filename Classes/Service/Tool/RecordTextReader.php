<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ReadsTheRunsWorkspaceTrait;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * A record and the text a model may read about it, for a run that has no tool to
 * read with or no reason to spend a round on one (ADR-236).
 *
 * The text is named column by column rather than taken from the whole row. A
 * page row holds its TSconfig and its author, and a custom table may hold
 * anything; only what is written for a reader leaves the installation.
 *
 * In a draft workspace a record and its page are read as that workspace shows
 * them, never another workspace's draft (ADR-265, with the row predicate of
 * ADR-263).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RecordTextReader
{
    use ReadsTheRunsWorkspaceTrait;
    use SafeCastTrait;

    public const MAX_LENGTH = 8000;

    private const PAGE_COLUMNS = ['title', 'subtitle', 'nav_title', 'abstract', 'keywords', 'description'];

    private const CONTENT_COLUMNS = ['header', 'subheader', 'bodytext'];

    /** Enough for a long page; what comes later is cut anyway. */
    private const MAX_CONTENT_ELEMENTS = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * The live row, or null for a deleted record or a workspace version.
     *
     * @return array<string, mixed>|null
     */
    public function liveRow(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false || self::toInt($row['t3ver_wsid'] ?? 0) !== 0 || self::toInt($row['t3ver_oid'] ?? 0) !== 0) {
            return null;
        }

        return $row;
    }

    /**
     * The record as workspace `$workspace` shows it, under its live uid, or
     * null: deleted there, a row of another workspace, or — in a draft
     * workspace — a table that keeps no versions or that core edits live from
     * a draft ({@see self::keepsDraftsOf()}), which a draft must not write
     * live. In live, {@see self::liveRow()}.
     *
     * `$uid` may name the live record, a record new in that workspace, or its
     * version there: a save in a workspace reaches the DataHandler's hooks
     * under the version's uid. The returned row's `uid` is the live uid either
     * way, the one a later write addresses.
     *
     * @return array<string, mixed>|null
     */
    public function rowIn(string $table, int $uid, int $workspace): ?array
    {
        if ($workspace <= 0) {
            return $this->liveRow($table, $uid);
        }

        if (!$this->keepsDraftsOf($table)) {
            return null;
        }

        $lookup = $this->connectionPool->getQueryBuilderForTable($table);
        $lookup->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $addressed = $lookup->select('uid', 't3ver_wsid', 't3ver_oid')
            ->from($table)
            ->where($lookup->expr()->eq('uid', $lookup->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if ($addressed === false || !in_array(self::toInt($addressed['t3ver_wsid'] ?? 0), [0, $workspace], true)) {
            return null;
        }

        $liveUid = $this->liveUid($addressed);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $this->isRecordInWorkspace($queryBuilder, $liveUid),
                $this->shownInWorkspace($queryBuilder, $table, $workspace),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $row['uid'] = $liveUid;

        return $row;
    }

    /**
     * Whether a write from a draft workspace stays a draft: the table keeps
     * versions, and is not one core edits live from any workspace
     * (`versioningWS_alwaysAllowLiveEdit`, which TYPO3 13.4 honours even for a
     * versioned table).
     */
    private function keepsDraftsOf(string $table): bool
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) ? ($tca[$table]['ctrl'] ?? null) : null;

        return $this->isWorkspaceAware($table)
            && (!is_array($ctrl) || !(bool) ($ctrl['versioningWS_alwaysAllowLiveEdit'] ?? false));
    }

    /**
     * @param array<string, mixed> $row
     * @param int                  $workspace the workspace the page's content is read in, 0 for live
     */
    public function textOf(string $table, array $row, int $workspace = 0): string
    {
        $parts = [];
        foreach ($table === 'pages' ? self::PAGE_COLUMNS : $this->readableColumns($table) as $column) {
            $parts[] = self::toStr($row[$column] ?? '');
        }

        if ($table === 'pages') {
            // The content of a translated page sits on the default page.
            $parent = self::toInt($row['l10n_parent'] ?? 0);
            array_push($parts, ...$this->contentOf($parent > 0 ? $parent : self::toInt($row['uid'] ?? 0), self::toInt($row['sys_language_uid'] ?? 0), $workspace));
        }

        // strip_tags() keeps what stands inside a script or a style element.
        $markup = (string) preg_replace('#<(script|style)\b.*?</\1\s*>#is', ' ', implode("\n", $parts));
        $text   = html_entity_decode(strip_tags(str_replace('<', ' <', $markup)), ENT_QUOTES | ENT_HTML5);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_LENGTH);
    }

    /**
     * @return list<string>
     */
    private function contentOf(int $pageUid, int $language, int $workspace): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');

        $queryBuilder->select(...self::CONTENT_COLUMNS)
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([$language, -1], Connection::PARAM_INT_ARRAY)),
                // A text for members must not come back in a public summary.
                $queryBuilder->expr()->in('fe_group', $queryBuilder->createNamedParameter(['', '0'], Connection::PARAM_STR_ARRAY)),
            );

        // In a draft workspace the elements as it shows them, moved there or
        // new there included; in live the live rows only.
        $queryBuilder->andWhere($this->readsWorkspaceRows('tt_content', $workspace)
            ? $this->shownInWorkspace($queryBuilder, 'tt_content', $workspace)
            : $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));

        $rows = $queryBuilder
            ->orderBy('sorting')
            ->setMaxResults(self::MAX_CONTENT_ELEMENTS)
            ->executeQuery()
            ->fetchAllAssociative();

        $parts = [];
        foreach ($rows as $row) {
            foreach (self::CONTENT_COLUMNS as $column) {
                $parts[] = self::toStr($row[$column] ?? '');
            }
        }

        return $parts;
    }

    /**
     * The label of a record and its prose: text columns without a special
     * editor, which would mark code or configuration, and without the editors'
     * internal notes.
     *
     * @return list<string>
     */
    private function readableColumns(string $table): array
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $label = $GLOBALS['TCA'][$table]['ctrl']['label'] ?? '';
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];

        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $notes = $GLOBALS['TCA'][$table]['ctrl']['descriptionColumn'] ?? '';

        $readable = is_string($label) && $label !== '' ? [$label] : [];
        foreach (is_array($columns) ? $columns : [] as $name => $column) {
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            if ($name !== $notes && is_array($config) && ($config['type'] ?? null) === 'text' && !isset($config['renderType'])) {
                $readable[] = (string) $name;
            }
        }

        return array_values(array_unique($readable));
    }
}
