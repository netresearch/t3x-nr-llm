<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Exception\PlanRefusedException;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
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
 * - **A process without a backend environment**, and a draft workspace the run
 *   did not record, through {@see WritesThroughDataHandlerTrait}.
 *
 * In the draft workspace the run was started in, the move is core's move
 * there (ADR-266): a move version of the page and of its translations, the
 * subpages and content following through their parent, live unchanged until
 * the workspace is published. The page tree — page, parent, anchor,
 * siblings, branch and site root — is read as that workspace shows it.
 */
final readonly class MovePageTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, RefusesBeforeApprovalInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;

    // The refusal before approval opens both entry points (ADR-266).
    use AsksItsRefusalFirstTrait;
    // Table, language, translations and record-level rights of an existing row.
    use ActsOnAnExistingRecordTrait;
    // The plan's checks throw their refusal instead of returning it.
    use RefusesPlanStepsTrait;

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
        private ApprovalPreviewTranslator $translator,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'move_page',
            'Move ONE page (default language) to a new parent page, or directly after a sibling page. Its '
            . 'content, translations and subpages move with it, and it keeps its uid and its URL path (the slug is '
            . 'not regenerated). Writes through the TYPO3 DataHandler as the acting backend user, in the workspace '
            . 'the request was started in: in live, or as a move in a draft workspace, where live stays unchanged '
            . 'until the workspace is published; the page tree is taken as that workspace shows it. Give "parent", '
            . '"after_page_uid", or both.',
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

    /**
     * @param array<string, mixed> $arguments
     */
    private function executeInItsWorkspace(array $arguments, ToolExecutionContext $context): ToolResult
    {
        // Asked first, as the approval scan asked it (ADR-266): a call it
        // refuses was not shown for approval and must write nothing.
        $user = $this->actingUserForTheWrite($arguments, $context, self::TABLE);
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
        $complaints = $this->complaintsNote($dataHandler);
        if (!$this->landedWherePlanned($plan['uid'], $plan['parent'], $plan['afterUid'], $user)) {
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
        foreach ($this->translationsShownTo(self::TABLE, $plan['uid'], $user) as $translation) {
            if (self::toInt($translation['pid'] ?? 0) !== $plan['parent']) {
                $strayTranslations[] = self::toInt($translation['uid'] ?? 0);
            }
        }

        // The page is where it was asked to go. Translations left behind make
        // the move partial (ADR-214); a complaint of TYPO3 beside a move that
        // the read-back shows complete does not, because nothing the call
        // planned is missing. The answer still names the complaint.
        $complete = $strayTranslations === [];

        return ToolResult::text(sprintf(
            'Moved page [%d] "%s" from under page [%d] to under page [%d]%s.%s Its URL path is unchanged: %s.%s',
            $plan['uid'],
            $this->excerpt($plan['title']),
            $plan['formerParent'],
            $plan['parent'],
            $plan['afterUid'] > 0 ? sprintf(', after page [%d]', $plan['afterUid']) : '',
            $complete
                ? $complaints
                : sprintf(' Not completely: translation(s) %s stayed behind.%s', implode(', ', $strayTranslations), $complaints),
            $plan['slug'] === '' ? '(none)' : $plan['slug'],
            $this->draftWorkspaceNote($user),
        ))->withWriteTarget(new RecordReference(self::TABLE, $plan['uid']), WriteKind::TEXT_UNCHANGED, $complete ? WriteCompleteness::COMPLETE : WriteCompleteness::PARTIAL);
    }

    /**
     * Both ends of the move and what travels with the page (ADR-136).
     *
     * The lines are in the language of the ACTING user and carry no field
     * names (ADR-213); a refusal line stays English, because it is the string
     * {@see self::execute()} hands the model as well.
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string. NOT checked
     * here: the backend-environment refusal, which describes the process
     * performing the write. A run that did not record its draft workspace gets
     * that refusal instead of the card (ADR-260).
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function previewCallInItsWorkspace(array $arguments, ToolExecutionContext $context): array
    {
        // Refused whatever the approver decides: the card says so instead
        // of describing a write that will not happen (ADR-266).
        $user = $this->actingUserForTheCard($arguments, $context);
        if (!$user instanceof BackendUserAuthentication) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        [$t, $q] = $this->translator->boundTo($user, $this->excerpt(...));

        // ADR-213, in the order of the editorial guidelines: what, where, the
        // current state, the new state, the consequences.
        $lines = [
            $t(ApprovalPreviewLabel::MovePageHeading),
            $t(ApprovalPreviewLabel::MovePagePage, $q($plan['title'])),
            $t(ApprovalPreviewLabel::MovePageCurrent, $q($plan['formerParentTitle'])),
            $plan['afterUid'] > 0
                ? $t(ApprovalPreviewLabel::MovePageNewAfter, $q($plan['parentTitle']), $q($plan['afterTitle']))
                : $t(ApprovalPreviewLabel::MovePageNewFirst, $q($plan['parentTitle'])),
            $plan['subpages'] > self::MAX_COUNTED_SUBPAGES
                ? $t(ApprovalPreviewLabel::MovePageMovesAlongMany, $plan['translations'], self::MAX_COUNTED_SUBPAGES)
                : $t(ApprovalPreviewLabel::MovePageMovesAlong, $plan['translations'], $plan['subpages']),
            $t(
                ApprovalPreviewLabel::MovePageUrlUnchanged,
                $plan['slug'] === '' ? $t(ApprovalPreviewLabel::ValueNone) : $plan['slug'],
            ),
            ...array_filter([$this->workspaceLine($t, $q, $context, $user)], is_string(...)),
        ];

        $details = [
            $t(ApprovalPreviewLabel::TechnicalPage, $plan['uid']),
            $t(ApprovalPreviewLabel::TechnicalFormerParent, $plan['formerParent']),
            $t(ApprovalPreviewLabel::TechnicalParentPage, $plan['parent']),
        ];
        if ($plan['afterUid'] > 0) {
            $details[] = $t(ApprovalPreviewLabel::TechnicalAnchorPage, $plan['afterUid']);
        }

        if ($plan['siteRootBefore'] !== $plan['siteRootAfter']) {
            $lines[] = $t(match (0) {
                $plan['siteRootAfter']  => ApprovalPreviewLabel::MovePageLeavesSite,
                $plan['siteRootBefore'] => ApprovalPreviewLabel::MovePageJoinsSite,
                default                 => ApprovalPreviewLabel::MovePageChangesSite,
            });
            $none      = $t(ApprovalPreviewLabel::ValueNone);
            $details[] = $t(ApprovalPreviewLabel::TechnicalSiteRootBefore, $plan['siteRootBefore'] > 0 ? (string) $plan['siteRootBefore'] : $none);
            $details[] = $t(ApprovalPreviewLabel::TechnicalSiteRootAfter, $plan['siteRootAfter'] > 0 ? (string) $plan['siteRootAfter'] : $none);
        }

        $lines[] = $this->translator->technical($user, $details);

        return $lines;
    }

    /**
     * Refused whatever the approver decides (ADR-266): in a draft workspace
     * the run did not record.
     *
     * @param array<string, mixed> $arguments
     */
    private function refusalBeforeApprovalInItsWorkspace(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        return $this->refusalInItsWorkspace($arguments, $context, null, self::TABLE);
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

    /**
     * The page the call moves, with no field (ADR-214).
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return PendingWriteTarget::fromArguments(self::TABLE, $arguments['uid'] ?? null);
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
        return self::planOrRefusal(fn (): array => $this->resolvePlan($arguments, $user));
    }

    /**
     * The checks of {@see self::plan()}, in order; the first that fails throws
     * its refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{uid:int, title:string, slug:string, translations:int, subpages:int, siteRootBefore:int, siteRootAfter:int, formerParent:int, formerParentTitle:string, parent:int, parentTitle:string, afterUid:int, afterTitle:string, destination:int}
     *
     * @throws PlanRefusedException
     */
    private function resolvePlan(array $arguments, BackendUserAuthentication $user): array
    {
        self::refuseIf($this->refuseUnknownArguments($arguments, ['uid', 'parent', 'after_page_uid'], 'moves one page'));

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            self::refuse('Refused: "uid" must be the positive uid of exactly one page.');
        }

        $parentUid = array_key_exists('parent', $arguments) ? self::toInt($arguments['parent']) : null;
        $afterUid  = array_key_exists('after_page_uid', $arguments) ? self::toInt($arguments['after_page_uid']) : null;
        if ($parentUid === null && $afterUid === null) {
            self::refuse('Refused: give "parent", "after_page_uid", or both, to say where the page goes.');
        }

        if (($parentUid !== null && $parentUid < 1) || ($afterUid !== null && $afterUid < 1)) {
            self::refuse('Refused: "parent" and "after_page_uid" must be positive page uids; this tool does not move a page '
                . 'to the root level.');
        }

        if ($parentUid === $uid || $afterUid === $uid) {
            self::refuse('Refused: a page can be neither its own parent nor its own anchor.');
        }

        // As the user's workspace shows the tree (ADR-266): a page new or
        // moved there where it is there, another workspace's draft not at all.
        $page = $this->fetchRowAsTheUserSees(self::TABLE, $uid, $user);
        // Visible to the user before any refusal below can name another page
        // in relation to this one.
        if ($page === null
            || !$user->doesUserHaveAccess($page, Permission::PAGE_SHOW)
            || !$this->mayEditRecord(self::TABLE, $page, $user)
        ) {
            self::refuse(self::NOT_PERMITTED);
        }

        $afterTitle = '';
        if ($afterUid !== null) {
            $anchor = $this->fetchRowAsTheUserSees(self::TABLE, $afterUid, $user);
            if ($anchor === null || $this->languageOf(self::TABLE, $anchor) !== 0
                || !$user->doesUserHaveAccess($anchor, Permission::PAGE_SHOW)
            ) {
                self::refuse(self::NOT_PERMITTED);
            }

            $anchorParent = self::toInt($anchor['pid'] ?? 0);
            if ($parentUid !== null && $anchorParent !== $parentUid) {
                self::refuse(sprintf('Refused: page [%d] is not under page [%d], so it cannot anchor the move.', $afterUid, $parentUid));
            }

            $parentUid  = $anchorParent;
            $afterTitle = self::toStr($anchor['title'] ?? '');
        }

        if ($parentUid === null || $parentUid < 1) {
            self::refuse('Refused: this tool does not move a page to the root level.');
        }

        $parent       = $this->fetchRowAsTheUserSees(self::TABLE, $parentUid, $user);
        $formerParent = self::toInt($page['pid'] ?? 0);
        $sameParent   = $parentUid === $formerParent;
        if ($parent === null
            || !$user->doesUserHaveAccess($page, $sameParent ? Permission::PAGE_EDIT : Permission::PAGE_DELETE)
            || (!$sameParent && !$user->doesUserHaveAccess($parent, Permission::PAGE_NEW))
        ) {
            self::refuse(self::NOT_PERMITTED);
        }

        // After the permission, because these name other pages.
        $parentLanguage = $this->languageOf(self::TABLE, $parent);
        if ($parentLanguage !== 0) {
            self::refuse(sprintf(
                'Refused: page [%d] is a translation (language %d) of page [%d]. Give the default-language page as '
                . '"parent".',
                $parentUid,
                $parentLanguage,
                $this->translationParentOf(self::TABLE, $parent),
            ));
        }

        $translationParent = $this->translationParentOf(self::TABLE, $page);
        if ($translationParent > 0 || $this->languageOf(self::TABLE, $page) !== 0) {
            self::refuse(sprintf(
                'Refused: page [%d] is a translation, and core moves a translation together with its default-language '
                . 'page. Move page [%d] instead.',
                $uid,
                $translationParent,
            ));
        }

        if ((bool) ($page['is_siteroot'] ?? false)) {
            self::refuse(sprintf(
                "Refused: page [%d] is a site root. Moving it changes where a site lives, which is an administrator's "
                . 'decision and not something this tool does.',
                $uid,
            ));
        }

        if ($this->liesInBranchOf($parentUid, $uid, $user)) {
            self::refuse(sprintf('Refused: page [%d] lies inside the branch of page [%d], so the page cannot move there.', $parentUid, $uid));
        }

        $translations = $this->translationsShownTo(self::TABLE, $uid, $user);
        $languages    = [];
        foreach ($translations as $translation) {
            // The record-level rights, not only the language: core moves each
            // translation under them, one at a time, and goes on past a refusal.
            if (!$this->mayEditRecord(self::TABLE, $translation, $user)) {
                $languages[] = $this->languageOf(self::TABLE, $translation);
            }
        }

        if ($languages !== []) {
            self::refuse(sprintf(
                'Refused: page [%d] has translations in language(s) %s which the acting backend user may not edit '
                . '(language, lock or page type), and core moves them with it. Nothing was written.',
                $uid,
                implode(', ', array_unique($languages)),
            ));
        }

        $formerParentRow = $this->fetchRowAsTheUserSees(self::TABLE, $formerParent, $user);

        return [
            'uid'               => $uid,
            'title'             => self::toStr($page['title'] ?? ''),
            'slug'              => self::toStr($page['slug'] ?? ''),
            'translations'      => count($translations),
            'subpages'          => $this->subpageCountOf($uid, $user),
            'siteRootBefore'    => $this->siteRootOf($formerParent, $user),
            'siteRootAfter'     => $this->siteRootOf($parentUid, $user),
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
     * without one — before every other default-language page there; in a
     * draft workspace as that workspace shows the page and its siblings.
     */
    private function landedWherePlanned(int $uid, int $parent, int $afterUid, BackendUserAuthentication $user): bool
    {
        $workspace = max(0, $user->workspace);
        $landed    = $this->fetchRowAsTheUserSees(self::TABLE, $uid, $user);
        if ($landed === null || self::toInt($landed['pid'] ?? 0) !== $parent) {
            return false;
        }

        $sorting  = self::toInt($landed['sorting'] ?? 0);
        $siblings = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $siblings->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $rows = $siblings
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $siblings->expr()->eq('pid', $siblings->createNamedParameter($parent, Connection::PARAM_INT)),
                $siblings->expr()->eq('sys_language_uid', $siblings->createNamedParameter(0, Connection::PARAM_INT)),
                ...$this->rowsShownIn($siblings, self::TABLE, $workspace, $this->liveVersionConstraints($siblings, self::TABLE)),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        // The sibling directly before the page: the last one sorted below it,
        // under its live uid. The page's own row sorts at its own place.
        $before = 0;
        foreach ($rows as $row) {
            if (self::toInt($row['sorting'] ?? 0) < $sorting) {
                $before = $this->liveUid($row);
            }
        }

        return $before === $afterUid;
    }

    /**
     * The default-language pages below a page, as the user's workspace shows
     * them, counted up to one past {@see self::MAX_COUNTED_SUBPAGES}. A count,
     * never titles: it includes pages the acting user cannot see.
     */
    private function subpageCountOf(int $uid, BackendUserAuthentication $user): int
    {
        $workspace = max(0, $user->workspace);
        $count     = 0;
        $pending   = [$uid];
        while ($pending !== [] && $count <= self::MAX_COUNTED_SUBPAGES) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $children = $queryBuilder
                // Every column: `t3ver_oid` exists only on a versioned table.
                ->select('*')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(array_shift($pending), Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    ...$this->rowsShownIn($queryBuilder, self::TABLE, $workspace, $this->liveVersionConstraints($queryBuilder, self::TABLE)),
                )
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($children as $child) {
                ++$count;
                // A subpage's own children name its live uid as their parent.
                $pending[] = $this->liveUid($child);
            }
        }

        return $count;
    }

    /**
     * The uid of the site root a page lies in — the page itself or the
     * nearest ancestor marked `is_siteroot` — or 0 outside every site; up the
     * tree as the user's workspace shows it.
     */
    private function siteRootOf(int $uid, BackendUserAuthentication $user): int
    {
        $current = $uid;
        for ($depth = 0; $current > 0 && $depth < self::MAX_ROOTLINE_DEPTH; ++$depth) {
            $row = $this->fetchRowAsTheUserSees(self::TABLE, $current, $user);
            if ($row === null) {
                return 0;
            }

            if ((bool) ($row['is_siteroot'] ?? false)) {
                return $current;
            }

            $current = self::toInt($row['pid'] ?? 0);
        }

        return 0;
    }

    /**
     * Whether `$candidate` is `$uid` or lies below it — walked up from the
     * candidate through its parents, as core's `destNotInsideSelf()` walks:
     * with each page where the user's workspace puts it.
     */
    private function liesInBranchOf(int $candidate, int $uid, BackendUserAuthentication $user): bool
    {
        $current = $candidate;
        for ($depth = 0; $current > 0 && $depth < self::MAX_ROOTLINE_DEPTH; ++$depth) {
            if ($current === $uid) {
                return true;
            }

            $row     = $this->fetchRowAsTheUserSees(self::TABLE, $current, $user);
            $current = $row === null ? 0 : self::toInt($row['pid'] ?? 0);
        }

        // A rootline deeper than the bound is not walked to the end; the
        // DataHandler asks the same question again inside the move.
        return false;
    }
}
