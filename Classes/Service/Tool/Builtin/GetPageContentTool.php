<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

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
 */
final readonly class GetPageContentTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const EXCERPT_LENGTH = 200;

    /** Upper bound on emitted content elements per call. */
    private const ROW_CAP = 100;

    /**
     * The '<' of non-inline tags only: a space inserted there keeps adjacent
     * text nodes ("<td>Price</td><td>100</td>") separated after strip_tags
     * without splitting words joined by inline markup ("cyber<b>security</b>").
     */
    private const NON_INLINE_TAG_PATTERN = '/<(?!\/?(?:a|abbr|b|bdi|bdo|cite|code|data|dfn|em|i|kbd|mark|q|s|samp|small|span|strong|sub|sup|time|u|var|wbr)\b)/i';

    public function __construct(
        protected ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_page_content',
            'Return one page (title, doktype, slug) and its content elements ordered by column and '
            . 'sorting: uid, colPos, CType, header and a short bodytext excerpt per element.',
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

    public function execute(array $arguments): string
    {
        $user = $this->actingBackendUser();
        if ($user === null) {
            return self::NOT_PERMITTED;
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return self::NOT_PERMITTED;
        }

        $language = self::toInt($arguments['language'] ?? 0);
        if ($language < 0) {
            $language = 0;
        }

        $isAdmin = $user->isAdmin();

        // Non-admins must hold PAGE_SHOW on the page itself; a missing page and
        // a denied page are indistinguishable in the reply (fail-closed).
        if (!$isAdmin) {
            $permsClause = self::toStr($user->getPagePermsClause(Permission::PAGE_SHOW));
            if (!is_array(BackendUtility::readPageAccess($uid, $permsClause))) {
                return self::NOT_PERMITTED;
            }
        }

        $page = $this->fetchPage($uid, $isAdmin);
        if ($page === null) {
            return self::NOT_PERMITTED;
        }

        $lines   = [];
        $lines[] = sprintf(
            'Page [%d] %s (doktype %d, slug %s)%s',
            self::toInt($page['uid'] ?? 0),
            self::toStr($page['title'] ?? ''),
            self::toInt($page['doktype'] ?? 0),
            self::toStr($page['slug'] ?? '') !== '' ? self::toStr($page['slug'] ?? '') : '-',
            self::toInt($page['hidden'] ?? 0) === 1 ? ' [hidden]' : '',
        );

        $rows = $this->fetchContent($uid, $language, $isAdmin);
        if ($rows === []) {
            $lines[] = sprintf('No content elements (language %d).', $language);

            return implode("\n", $lines);
        }

        $lines[] = sprintf('Content elements (%d, language %d):', count($rows), $language);
        foreach ($rows as $row) {
            $header  = self::toStr($row['header'] ?? '');
            $lines[] = sprintf(
                '[%d] colPos=%d %s · %s%s',
                self::toInt($row['uid'] ?? 0),
                self::toInt($row['colPos'] ?? 0),
                self::toStr($row['CType'] ?? ''),
                $header !== '' ? $header : '(no header)',
                self::toInt($row['hidden'] ?? 0) === 1 ? ' [hidden]' : '',
            );

            $excerpt = $this->excerpt(self::toStr($row['bodytext'] ?? ''));
            if ($excerpt !== '') {
                $lines[] = '  ' . $excerpt;
            }
        }

        return implode("\n", $lines);
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
    private function fetchPage(int $uid, bool $isAdmin): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        if ($isAdmin) {
            // Admins may inspect hidden and timed-out pages; the [hidden]
            // marker makes it explicit. Soft-deleted rows stay excluded.
            $queryBuilder->getRestrictions()
                ->removeByType(HiddenRestriction::class)
                ->removeByType(StartTimeRestriction::class)
                ->removeByType(EndTimeRestriction::class);
        }

        $row = $queryBuilder
            ->select('uid', 'title', 'doktype', 'slug', 'hidden')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The page's content elements in column/sorting order. Admins also see
     * hidden elements (marked); non-admins get the default restrictions.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchContent(int $pageUid, int $language, bool $isAdmin): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        if ($isAdmin) {
            $queryBuilder->getRestrictions()
                ->removeByType(HiddenRestriction::class)
                ->removeByType(StartTimeRestriction::class)
                ->removeByType(EndTimeRestriction::class);
        }

        $rows = $queryBuilder
            ->select('uid', 'colPos', 'CType', 'header', 'bodytext', 'hidden')
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

        return array_values($rows);
    }

    /**
     * A short single-line, tag-stripped bodytext excerpt.
     */
    private function excerpt(string $bodytext): string
    {
        // Space before each non-inline tag so adjacent text nodes stay
        // separated after strip_tags; the collapse removes the extra spaces.
        $spaced = (string)preg_replace(self::NON_INLINE_TAG_PATTERN, ' <', $bodytext);
        $plain  = trim((string)preg_replace('/\s+/', ' ', strip_tags($spaced)));
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
