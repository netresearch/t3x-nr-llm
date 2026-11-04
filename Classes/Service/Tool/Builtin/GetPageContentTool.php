<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Return one page's header data and its content elements in column/sorting
 * order.
 *
 * Inspired by the GetPage tool of EXT:mcp_server (hauptsache.net,
 * GPL-2.0-or-later); own implementation. Lists per content element the uid,
 * column, CType, header, hidden flag (admins only — non-admins never see
 * hidden elements) and a short tag-stripped bodytext excerpt.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): fail-closed
 * without a backend user; non-admins must hold PAGE_SHOW permission on the
 * page (checked via the acting user's page-perms clause) and get default
 * query restrictions (no hidden/timed rows). A missing page and a denied
 * page return the same neutral string, so the model cannot probe page
 * existence.
 *
 * It reads the page as the acting user sees it in their workspace (ADR-260):
 * in live the live rows; in a draft workspace each row overlaid with its
 * version there, the elements new or moved there included and the ones
 * deleted or moved away left out. Another workspace's drafts are never read.
 */
final readonly class GetPageContentTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;

    use SafeCastTrait;
    use ChecksPageAccessAsActingUserTrait;

    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const EXCERPT_LENGTH = 200;

    /**
     * The core translation-parent columns of the two tables this tool reads.
     * Hardcoded like `sys_language_uid` already is here: both are core columns
     * of core tables, not installation-specific TCA.
     */
    private const PAGE_PARENT_FIELD = 'l10n_parent';

    private const CONTENT_PARENT_FIELD = 'l18n_parent';

    /** `t3ver_state` of a record deleted in a workspace, and of a record moved there. */
    private const DELETE_PLACEHOLDER = 2;

    private const MOVE_POINTER = 4;

    /** Upper bound on emitted content elements per call. */
    private const ROW_CAP = 100;

    /**
     * The '<' of non-inline tags only: a space inserted there keeps adjacent
     * text nodes ("<td>Price</td><td>100</td>") separated after strip_tags
     * without splitting words joined by inline markup ("cyber<b>security</b>").
     */
    private const NON_INLINE_TAG_PATTERN = '/<(?!\/?(?:a|abbr|b|bdi|bdo|cite|code|data|dfn|em|i|kbd|mark|q|s|samp|small|span|strong|sub|sup|time|u|var|wbr)\b)/i';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_page_content',
            'Return one page (title, doktype, slug, language) and its content elements in ONE language, '
            . 'ordered by column and sorting: uid, colPos, CType, header and a short bodytext excerpt per '
            . 'element; a translated element names the element it translates. The result also lists the '
            . 'other languages that hold content on the page — call again with "language" to read them.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The page uid to inspect.',
                    ],
                    'language' => [
                        'type'        => 'integer',
                        'description' => 'sys_language_uid of the content to list (default 0).',
                    ],
                ],
                'required' => ['uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $language = self::toInt($arguments['language'] ?? 0);
        if ($language < 0) {
            $language = 0;
        }

        $isAdmin   = $user->isAdmin();
        $workspace = max(0, $user->workspace);

        // Non-admins may only read content in languages they are permitted;
        // otherwise a language restriction is a no-op against this tool.
        if (!$isAdmin && !$user->checkLanguageAccess($language)) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        // Non-admins must hold PAGE_SHOW on the page itself; a missing page and
        // a denied page are indistinguishable in the reply (fail-closed).
        if (!$isAdmin) {
            $permsClause = self::toStr($user->getPagePermsClause(Permission::PAGE_SHOW));
            if (!$this->actingUserMayAccessPage($user, $uid, $permsClause)) {
                return ToolResult::text(self::NOT_PERMITTED);
            }
        }

        $page = $this->fetchPage($uid, $isAdmin, $workspace);
        if ($page === null) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $pageParent = self::toInt($page[self::PAGE_PARENT_FIELD] ?? 0);
        $lines      = [];
        $lines[]    = sprintf(
            'Page [%d] %s (doktype %d, slug %s, language %d%s)%s',
            self::toInt($page['uid'] ?? 0),
            self::toStr($page['title'] ?? ''),
            self::toInt($page['doktype'] ?? 0),
            self::toStr($page['slug'] ?? '') !== '' ? self::toStr($page['slug'] ?? '') : '-',
            self::toInt($page['sys_language_uid'] ?? 0),
            $pageParent > 0 ? sprintf(', translation of page [%d]', $pageParent) : '',
            self::toInt($page['hidden'] ?? 0) === 1 ? ' [hidden]' : '',
        );

        // A translated page owns no content rows: its translated elements sit
        // on the default-language page, the one it translates. Reading them by
        // the translation's own uid found nothing and read as an empty page.
        $contentPage = $pageParent > 0 ? $pageParent : $uid;
        if ($contentPage !== $uid && !$isAdmin) {
            $permsClause = self::toStr($user->getPagePermsClause(Permission::PAGE_SHOW));
            if (!$this->actingUserMayAccessPage($user, $contentPage, $permsClause)) {
                return ToolResult::text(self::NOT_PERMITTED);
            }
        }

        $otherLanguages = $this->otherLanguagesLine($contentPage, $language, $isAdmin, $user, $workspace);

        $rows = $this->fetchContent($contentPage, $language, $isAdmin, $workspace);
        if ($rows === []) {
            $lines[] = sprintf('No content elements (language %d).', $language);
            if ($otherLanguages !== '') {
                $lines[] = $otherLanguages;
            }

            return ToolResult::text(implode("\n", $lines));
        }

        $lines[] = sprintf('Content elements (%d, language %d):', count($rows), $language);
        foreach ($rows as $row) {
            $header  = self::toStr($row['header'] ?? '');
            $parent  = self::toInt($row[self::CONTENT_PARENT_FIELD] ?? 0);
            $lines[] = sprintf(
                '[%d] colPos=%d %s · %s%s%s',
                self::toInt($row['uid'] ?? 0),
                self::toInt($row['colPos'] ?? 0),
                self::toStr($row['CType'] ?? ''),
                $header !== '' ? $header : '(no header)',
                self::toInt($row['hidden'] ?? 0) === 1 ? ' [hidden]' : '',
                $parent > 0 ? sprintf(' · translation of [%d]', $parent) : '',
            );

            $excerpt = $this->excerpt(self::toStr($row['bodytext'] ?? ''));
            if ($excerpt !== '') {
                $lines[] = '  ' . $excerpt;
            }
        }

        if ($otherLanguages !== '') {
            $lines[] = $otherLanguages;
        }

        return ToolResult::text(implode("\n", $lines));
    }

    /**
     * One line naming the OTHER languages that hold content on the page, or ''
     * when there are none (NEXT-167).
     *
     * Without it the tool answered only for the language it was asked about,
     * and the model reported a page as having one hidden element while a
     * visible translation of it sat next to it (demo conversation 91). Only
     * languages the acting user may access are counted, with the same
     * restrictions the element list itself uses.
     *
     * Elements for all languages (`sys_language_uid = -1`) are left out: the
     * tool cannot be asked for language -1 (a negative argument reads as 0),
     * and no element list of it includes them, so naming them here would send
     * the model after a language it can never read.
     *
     * In a draft workspace the count takes the live rows and the rows new or
     * moved there, without overlaying each: an element deleted or moved away
     * in the workspace still counts. It is a hint to call again, and the
     * list that call returns is overlaid.
     */
    private function otherLanguagesLine(int $pageUid, int $language, bool $isAdmin, BackendUserAuthentication $user, int $workspace): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspace));
        if ($isAdmin) {
            $queryBuilder->getRestrictions()
                ->removeByType(HiddenRestriction::class)
                ->removeByType(StartTimeRestriction::class)
                ->removeByType(EndTimeRestriction::class);
        }

        $rows = $queryBuilder
            ->select('sys_language_uid')
            ->addSelectLiteral('COUNT(*) AS elements')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($language, Connection::PARAM_INT),
                ),
                $queryBuilder->expr()->gte('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('sys_language_uid')
            ->orderBy('sys_language_uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $parts = [];
        foreach ($rows as $row) {
            $other = self::toInt($row['sys_language_uid'] ?? 0);
            if (!$isAdmin && !$user->checkLanguageAccess($other)) {
                continue;
            }

            $parts[] = sprintf('language %d (%d)', $other, self::toInt($row['elements'] ?? 0));
        }

        return $parts === []
            ? ''
            : 'Content in other languages on this page: ' . implode(', ', $parts) . '. Call again with "language" to list it.';
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
     * The page row, or null when it does not exist (or, for non-admins, is
     * hidden/timed out via the default restrictions).
     *
     * @return array<string, mixed>|null
     */
    private function fetchPage(int $uid, bool $isAdmin, int $workspace): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Never send another workspace's drafts to the external LLM provider
        // (the default restrictions do not exclude them); the acting user's
        // own workspace is what they work on (ADR-260). Applies to admins too.
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspace));
        if ($isAdmin || $workspace > 0) {
            // Admins may inspect hidden and timed-out pages; the [hidden]
            // marker makes it explicit. Soft-deleted rows stay excluded. In a
            // draft workspace the version decides, so the check moves behind
            // the overlay (ADR-260).
            $queryBuilder->getRestrictions()
                ->removeByType(HiddenRestriction::class)
                ->removeByType(StartTimeRestriction::class)
                ->removeByType(EndTimeRestriction::class);
        }

        $row = $queryBuilder
            ->select('uid', 'pid', 'title', 'doktype', 'slug', 'hidden', 'starttime', 'endtime', 'sys_language_uid', self::PAGE_PARENT_FIELD, 't3ver_oid', 't3ver_state')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                // By uid: the live page or one new in the workspace, never a
                // version row, which is reached through its live page.
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        // A page moved in the workspace is still this page, elsewhere.
        $row = $this->inWorkspace('pages', $row, $workspace, false);

        return $row === null || (!$isAdmin && !$this->isVisibleNow($row)) ? null : $row;
    }

    /**
     * The page's content elements in column/sorting order. Admins also see
     * hidden elements (marked); non-admins get the default restrictions.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchContent(int $pageUid, int $language, bool $isAdmin, int $workspace): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Never send another workspace's drafts to the external LLM provider
        // (the default restrictions do not exclude them); the acting user's
        // own workspace is what they work on (ADR-260). Applies to admins too.
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspace));
        if ($isAdmin || $workspace > 0) {
            // In a draft workspace the version decides whether an element is
            // visible, so the check moves behind the overlay (ADR-260).
            $queryBuilder->getRestrictions()
                ->removeByType(HiddenRestriction::class)
                ->removeByType(StartTimeRestriction::class)
                ->removeByType(EndTimeRestriction::class);
        }

        $rows = $queryBuilder
            ->select('uid', 'pid', 'colPos', 'sorting', 'CType', 'header', 'bodytext', 'hidden', 'starttime', 'endtime', 'sys_language_uid', self::CONTENT_PARENT_FIELD, 't3ver_oid', 't3ver_state')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($language, Connection::PARAM_INT),
                ),
            )
            ->orderBy('colPos', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->setMaxResults(self::ROW_CAP)
            ->executeQuery()
            ->fetchAllAssociative();

        if ($workspace === 0) {
            return array_values($rows);
        }

        $shown = [];
        foreach ($rows as $row) {
            $row = $this->inWorkspace('tt_content', $row, $workspace, true);
            if ($row === null
                || self::toInt($row['pid'] ?? 0) !== $pageUid
                || self::toInt($row['sys_language_uid'] ?? 0) !== $language
                || (!$isAdmin && !$this->isVisibleNow($row))
            ) {
                continue;
            }

            $shown[] = $row;
        }

        // The workspace's own order: a version may carry another column or
        // position than its live row.
        usort($shown, static fn (array $a, array $b): int => [self::toInt($a['colPos'] ?? 0), self::toInt($a['sorting'] ?? 0)]
            <=> [self::toInt($b['colPos'] ?? 0), self::toInt($b['sorting'] ?? 0)]);

        return $shown;
    }

    /**
     * What the hidden, start-time and end-time restrictions ask, asked of a
     * row after its workspace overlay: not hidden, started, not ended.
     *
     * @param array<string, mixed> $row
     */
    private function isVisibleNow(array $row): bool
    {
        $now   = self::toInt(GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'accessTime', 0));
        $start = self::toInt($row['starttime'] ?? 0);
        $end   = self::toInt($row['endtime'] ?? 0);

        return self::toInt($row['hidden'] ?? 0) === 0 && $start <= $now && ($end === 0 || $end > $now);
    }

    /**
     * A row as the acting user's workspace shows it, under its live uid, or
     * null when it is not on the page there (ADR-260).
     *
     * The query returns, besides live rows and rows new in the workspace, the
     * versions of rows MOVED there in the workspace: such a row carries the
     * version's values already and names its live record. A row deleted in the
     * workspace is not shown, nor, with `$hereOnly`, a live row moved
     * elsewhere there.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function inWorkspace(string $table, array $row, int $workspace, bool $hereOnly): ?array
    {
        if ($workspace === 0) {
            return $row;
        }

        $original = self::toInt($row['t3ver_oid'] ?? 0);
        if ($original > 0) {
            return self::toInt($row['t3ver_state'] ?? 0) === self::MOVE_POINTER ? ['uid' => $original] + $row : null;
        }

        BackendUtility::workspaceOL($table, $row, $workspace, $hereOnly);

        if (!is_array($row) || self::toInt($row['t3ver_state'] ?? 0) === self::DELETE_PLACEHOLDER) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * A short single-line, tag-stripped bodytext excerpt.
     */
    private function excerpt(string $bodytext): string
    {
        // Space before each non-inline tag so adjacent text nodes stay
        // separated after strip_tags; the collapse removes the extra spaces.
        $spaced = (string) preg_replace(self::NON_INLINE_TAG_PATTERN, ' <', $bodytext);
        $plain  = trim((string) preg_replace('/\s+/', ' ', strip_tags($spaced)));
        if ($plain === '') {
            return '';
        }

        if (mb_strlen($plain) > self::EXCERPT_LENGTH) {
            return mb_substr($plain, 0, self::EXCERPT_LENGTH) . '…';
        }

        return $plain;
    }

    public function getGroup(): string
    {
        return 'content';
    }
}
