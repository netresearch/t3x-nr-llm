<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Move ONE page to a new parent, or next to a sibling, through the
 * DataHandler, as the acting backend user (ADR-198).
 *
 * The page keeps its uid, its content, its translations and its subpages —
 * they move with it, as they do in the page tree. It also keeps its URL path:
 * core does not regenerate `slug` when a page moves, so the page answers at
 * its old address under its new parent until someone recomputes the slug.
 * The approval card says so, because an approver who expects the URL to
 * follow the tree would otherwise approve a result they did not picture.
 *
 * What it refuses, and why:
 *
 * - **A page translation.** Core keeps a translation's position in step with
 *   its default-language page; the refusal names that page.
 * - **A site root**, for the reason {@see DeleteRecordTool} refuses one.
 * - **A target inside the page's own branch**, and the page as its own anchor.
 * - **A move the acting user may not make**, asked as core's moveRecord()
 *   asks it: to another parent, `PAGE_DELETE` on the page and `PAGE_NEW` on the
 *   new parent; within the same parent, `PAGE_EDIT` on the page. Plus the
 *   record-level rights ({@see ActsOnAnExistingRecordTrait::mayEditRecord()}),
 *   and access to the language of every translation that moves along.
 * - **A draft workspace and a process without a backend environment**, through
 *   {@see WritesThroughDataHandlerTrait}.
 */
final readonly class MovePageTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;
    // Table, language, translations and record-level rights of an existing row.
    use ActsOnAnExistingRecordTrait;

    /**
     * One string for "no such page", "deleted" and "you may not move it or
     * move it there", so a refusal never confirms that a uid exists. Shared
     * with the other page-addressing tools.
     */
    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const TABLE = 'pages';

    /** Beyond this many, the card says "more than" rather than counting on. */
    private const MAX_COUNTED_SUBPAGES = 100;

    /** The deepest rootline walked to find out whether a target lies inside the page's own branch. */
    private const MAX_ROOTLINE_DEPTH = 100;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'move_page',
            'Move ONE page (default language) to a new parent page, or directly after a sibling page. Its '
            . 'content, translations and subpages move with it, and it keeps its uid and its URL path (the slug is '
            . 'not regenerated). Writes through the TYPO3 DataHandler as the acting backend user, in the live '
            . 'workspace only. Give "parent", "after_page_uid", or both.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the single page to move.',
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the page the moved page should end up under. Omit when '
                            . '"after_page_uid" is given; the page then goes under that page\'s parent.',
                    ],
                    'after_page_uid' => [
                        'type'        => 'integer',
                        'description' => 'Place the page directly after this sibling. It must be under "parent" '
                            . 'when both are given. Omit to place the page first under "parent".',
                    ],
                ],
                'required' => ['uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->writableActingUser($context, self::TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], [self::TABLE => [$plan['uid'] => ['move' => $plan['destination']]]], $user);
        $dataHandler->process_cmdmap();

        // Read back whatever the error log says: the DataHandler declines a
        // move without always writing to `errorLog`, and it moves translations
        // one at a time and goes on past a refusal, so a complaint does not
        // mean the page stayed. Parent AND position are compared — a reorder
        // under the same parent changes no `pid`.
        $complaints = $dataHandler->errorLog === [] ? '' : ' TYPO3 reported: ' . $this->summariseErrors($dataHandler->errorLog);
        if (!$this->landedWherePlanned($plan['uid'], $plan['parent'], $plan['afterUid'])) {
            return ToolResult::error(sprintf(
                'The move did not take: page [%d] is not %s under page [%d] afterwards.%s The acting backend user is most '
                . 'likely missing a permission core asks for on one of the two pages.',
                $plan['uid'],
                $plan['afterUid'] > 0 ? sprintf('directly after page [%d]', $plan['afterUid']) : 'first',
                $plan['parent'],
                $complaints,
            ));
        }

        $strayTranslations = [];
        foreach ($this->translationsOf(self::TABLE, $plan['uid']) as $translation) {
            if (self::toInt($translation['pid'] ?? 0) !== $plan['parent']) {
                $strayTranslations[] = self::toInt($translation['uid'] ?? 0);
            }
        }

        return ToolResult::text(sprintf(
            'Moved page [%d] "%s" from under page [%d] to under page [%d]%s.%s Its URL path is unchanged: %s',
            $plan['uid'],
            $this->excerpt($plan['title']),
            $plan['formerParent'],
            $plan['parent'],
            $plan['afterUid'] > 0 ? sprintf(', after page [%d]', $plan['afterUid']) : '',
            $strayTranslations === [] && $complaints === ''
                ? ''
                : sprintf(
                    ' Not completely:%s%s',
                    $strayTranslations === [] ? '' : ' translation(s) ' . implode(', ', $strayTranslations) . ' stayed behind.',
                    $complaints,
                ),
            $plan['slug'] === '' ? '(none)' : $plan['slug'],
        ))->withWriteTarget(new RecordReference(self::TABLE, $plan['uid']), WriteKind::UPDATED);
    }

    /**
     * Both ends of the move and what travels with the page (ADR-136).
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string. NOT checked
     * here: the live-workspace and backend-environment refusals, which describe
     * the process performing the write.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [self::NOT_PERMITTED];
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        $lines = [
            sprintf(
                'Page [%d] "%s", with its content, %d translation(s) and %s:',
                $plan['uid'],
                $this->excerpt($plan['title']),
                $plan['translations'],
                $plan['subpages'] > self::MAX_COUNTED_SUBPAGES
                    ? sprintf('more than %d subpages', self::MAX_COUNTED_SUBPAGES)
                    : sprintf('%d subpage(s)', $plan['subpages']),
            ),
            sprintf('from: under page [%d] "%s"', $plan['formerParent'], $this->excerpt($plan['formerParentTitle'])),
            $plan['afterUid'] > 0
                ? sprintf(
                    'to: under page [%d] "%s", directly after page [%d] "%s"',
                    $plan['parent'],
                    $this->excerpt($plan['parentTitle']),
                    $plan['afterUid'],
                    $this->excerpt($plan['afterTitle']),
                )
                : sprintf('to: under page [%d] "%s", first', $plan['parent'], $this->excerpt($plan['parentTitle'])),
            sprintf('URL path unchanged: %s (the slug is not regenerated)', $plan['slug'] === '' ? '(none)' : $plan['slug']),
        ];

        if ($plan['siteRootBefore'] !== $plan['siteRootAfter']) {
            $lines[] = match (0) {
                $plan['siteRootAfter'] => sprintf(
                    'moves out of its site: from the site of root page [%d] to outside every site — outside a site the '
                    . 'page has no frontend address',
                    $plan['siteRootBefore'],
                ),
                $plan['siteRootBefore'] => sprintf(
                    'moves into a site: from outside every site to the site of root page [%d] — its address follows that '
                    . 'site from then on',
                    $plan['siteRootAfter'],
                ),
                default => sprintf(
                    'moves into another site: from the site of root page [%d] to the site of root page [%d] — its address '
                    . 'follows the other site from then on',
                    $plan['siteRootBefore'],
                    $plan['siteRootAfter'],
                ),
            };
        }

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the page permissions are the acting user's
        // own, checked here and enforced a second time by the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Moving a page to a named parent and anchor converges: a repeat finds
        // it there and puts it in the same place.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * Everything the move needs, resolved and authorised — or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{uid:int, title:string, slug:string, translations:int, subpages:int, siteRootBefore:int, siteRootAfter:int, formerParent:int, formerParentTitle:string, parent:int, parentTitle:string, afterUid:int, afterTitle:string, destination:int}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments($arguments, ['uid', 'parent', 'after_page_uid'], 'moves one page');
        if ($unknown !== null) {
            return $unknown;
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive uid of exactly one page.';
        }

        $parentUid = array_key_exists('parent', $arguments) ? self::toInt($arguments['parent']) : null;
        $afterUid  = array_key_exists('after_page_uid', $arguments) ? self::toInt($arguments['after_page_uid']) : null;
        if ($parentUid === null && $afterUid === null) {
            return 'Refused: give "parent", "after_page_uid", or both, to say where the page goes.';
        }

        if (($parentUid !== null && $parentUid < 1) || ($afterUid !== null && $afterUid < 1)) {
            return 'Refused: "parent" and "after_page_uid" must be positive page uids; this tool does not move a page '
                . 'to the root level.';
        }

        if ($parentUid === $uid || $afterUid === $uid) {
            return 'Refused: a page can be neither its own parent nor its own anchor.';
        }

        $page = $this->fetchRowByUid(self::TABLE, $uid);
        // Visible to the user before any refusal below can name another page
        // in relation to this one.
        if ($page === null
            || !$user->doesUserHaveAccess($page, Permission::PAGE_SHOW)
            || !$this->mayEditRecord(self::TABLE, $page, $user)
        ) {
            return self::NOT_PERMITTED;
        }

        $afterTitle = '';
        if ($afterUid !== null) {
            $anchor = $this->fetchRowByUid(self::TABLE, $afterUid);
            if ($anchor === null || $this->languageOf(self::TABLE, $anchor) !== 0
                || !$user->doesUserHaveAccess($anchor, Permission::PAGE_SHOW)
            ) {
                return self::NOT_PERMITTED;
            }

            $anchorParent = self::toInt($anchor['pid'] ?? 0);
            if ($parentUid !== null && $anchorParent !== $parentUid) {
                return sprintf('Refused: page [%d] is not under page [%d], so it cannot anchor the move.', $afterUid, $parentUid);
            }

            $parentUid  = $anchorParent;
            $afterTitle = self::toStr($anchor['title'] ?? '');
        }

        if ($parentUid === null || $parentUid < 1) {
            return 'Refused: this tool does not move a page to the root level.';
        }

        $parent       = $this->fetchRowByUid(self::TABLE, $parentUid);
        $formerParent = self::toInt($page['pid'] ?? 0);
        $sameParent   = $parentUid === $formerParent;
        if ($parent === null
            || !$user->doesUserHaveAccess($page, $sameParent ? Permission::PAGE_EDIT : Permission::PAGE_DELETE)
            || (!$sameParent && !$user->doesUserHaveAccess($parent, Permission::PAGE_NEW))
        ) {
            return self::NOT_PERMITTED;
        }

        // After the permission, because these name other pages.
        $parentLanguage = $this->languageOf(self::TABLE, $parent);
        if ($parentLanguage !== 0) {
            return sprintf(
                'Refused: page [%d] is a translation (language %d) of page [%d]. Give the default-language page as '
                . '"parent".',
                $parentUid,
                $parentLanguage,
                $this->translationParentOf(self::TABLE, $parent),
            );
        }

        $translationParent = $this->translationParentOf(self::TABLE, $page);
        if ($translationParent > 0 || $this->languageOf(self::TABLE, $page) !== 0) {
            return sprintf(
                'Refused: page [%d] is a translation, and core moves a translation together with its default-language '
                . 'page. Move page [%d] instead.',
                $uid,
                $translationParent,
            );
        }

        if ((bool)($page['is_siteroot'] ?? false)) {
            return sprintf(
                "Refused: page [%d] is a site root. Moving it changes where a site lives, which is an administrator's "
                . 'decision and not something this tool does.',
                $uid,
            );
        }

        if ($this->liesInBranchOf($parentUid, $uid)) {
            return sprintf('Refused: page [%d] lies inside the branch of page [%d], so the page cannot move there.', $parentUid, $uid);
        }

        $translations = $this->translationsOf(self::TABLE, $uid);
        $languages    = [];
        foreach ($translations as $translation) {
            // The record-level rights, not only the language: core moves each
            // translation under them, one at a time, and goes on past a refusal.
            if (!$this->mayEditRecord(self::TABLE, $translation, $user)) {
                $languages[] = $this->languageOf(self::TABLE, $translation);
            }
        }

        if ($languages !== []) {
            return sprintf(
                'Refused: page [%d] has translations in language(s) %s which the acting backend user may not edit '
                . '(language, lock or page type), and core moves them with it. Nothing was written.',
                $uid,
                implode(', ', array_unique($languages)),
            );
        }

        $formerParentRow = $this->fetchRowByUid(self::TABLE, $formerParent, 'uid', 'title');

        return [
            'uid'               => $uid,
            'title'             => self::toStr($page['title'] ?? ''),
            'slug'              => self::toStr($page['slug'] ?? ''),
            'translations'      => count($translations),
            'subpages'          => $this->subpageCountOf($uid),
            'siteRootBefore'    => $this->siteRootOf($formerParent),
            'siteRootAfter'     => $this->siteRootOf($parentUid),
            'formerParent'      => $formerParent,
            'formerParentTitle' => self::toStr($formerParentRow['title'] ?? ''),
            'parent'            => $parentUid,
            'parentTitle'       => self::toStr($parent['title'] ?? ''),
            'afterUid'          => $afterUid ?? 0,
            'afterTitle'        => $afterTitle,
            // The DataHandler's convention: a positive destination is the new
            // parent, a negative one is "directly after the page with that uid".
            'destination' => $afterUid !== null ? -$afterUid : $parentUid,
        ];
    }

    /**
     * Whether the page sits under the parent, directly after the anchor or —
     * without one — before every other default-language page there.
     */
    private function landedWherePlanned(int $uid, int $parent, int $afterUid): bool
    {
        $landed = $this->fetchRowByUid(self::TABLE, $uid, 'uid', 'pid', 'sorting');
        if ($landed === null || self::toInt($landed['pid'] ?? 0) !== $parent) {
            return false;
        }

        $sorting  = self::toInt($landed['sorting'] ?? 0);
        $siblings = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $siblings->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $rows = $siblings
            ->select('uid', 'sorting')
            ->from(self::TABLE)
            ->where(
                $siblings->expr()->eq('pid', $siblings->createNamedParameter($parent, Connection::PARAM_INT)),
                $siblings->expr()->eq('sys_language_uid', $siblings->createNamedParameter(0, Connection::PARAM_INT)),
                $siblings->expr()->neq('uid', $siblings->createNamedParameter($uid, Connection::PARAM_INT)),
                ...$this->liveVersionConstraints($siblings, self::TABLE),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        // The sibling directly before the page: the last one sorted below it.
        $before = 0;
        foreach ($rows as $row) {
            if (self::toInt($row['sorting'] ?? 0) < $sorting) {
                $before = self::toInt($row['uid'] ?? 0);
            }
        }

        return $before === $afterUid;
    }

    /**
     * The live default-language pages below a page, counted up to one past
     * {@see self::MAX_COUNTED_SUBPAGES}. A count, never titles: it includes
     * pages the acting user cannot see.
     */
    private function subpageCountOf(int $uid): int
    {
        $count   = 0;
        $pending = [$uid];
        while ($pending !== [] && $count <= self::MAX_COUNTED_SUBPAGES) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $children = $queryBuilder
                ->select('uid')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(array_shift($pending), Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    ...$this->liveVersionConstraints($queryBuilder, self::TABLE),
                )
                ->executeQuery()
                ->fetchFirstColumn();
            foreach ($children as $child) {
                $count++;
                $pending[] = self::toInt($child);
            }
        }

        return $count;
    }

    /**
     * The uid of the site root a page lies in — the page itself or the
     * nearest ancestor marked `is_siteroot` — or 0 outside every site.
     */
    private function siteRootOf(int $uid): int
    {
        $current = $uid;
        for ($depth = 0; $current > 0 && $depth < self::MAX_ROOTLINE_DEPTH; $depth++) {
            $row = $this->fetchRowByUid(self::TABLE, $current, 'uid', 'pid', 'is_siteroot');
            if ($row === null) {
                return 0;
            }

            if ((bool)($row['is_siteroot'] ?? false)) {
                return $current;
            }

            $current = self::toInt($row['pid'] ?? 0);
        }

        return 0;
    }

    /**
     * Whether `$candidate` is `$uid` or lies below it — walked up from the
     * candidate through its parents, as core's `destNotInsideSelf()` walks.
     */
    private function liesInBranchOf(int $candidate, int $uid): bool
    {
        $current = $candidate;
        for ($depth = 0; $current > 0 && $depth < self::MAX_ROOTLINE_DEPTH; $depth++) {
            if ($current === $uid) {
                return true;
            }

            $row     = $this->fetchRowByUid(self::TABLE, $current, 'uid', 'pid');
            $current = $row === null ? 0 : self::toInt($row['pid'] ?? 0);
        }

        // A rootline deeper than the bound is not walked to the end; the
        // DataHandler asks the same question again inside the move.
        return false;
    }
}
