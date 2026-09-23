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
use Netresearch\NrLlm\Service\Tool\RecordCreatorInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Copy ONE content element to a page and column, or ONE page — without its
 * subpages — under a parent, through the DataHandler, as the acting backend
 * user (ADR-198, ADR-199).
 *
 * The copy is core's own copy command, so it brings along what core brings:
 * the translations of a default-language record, the file references and
 * inline children of each copied row, and for a page the records stored on
 * it (for a non-admin, those of the tables in `tables_modify`, as core copies
 * them). The approval card counts that before anything happens. What the
 * tool adds is one rule the draft writers already follow: **the copy is
 * hidden.** Core hides a copy only where the table asks for it and the user
 * has not switched it off; this tool sets the hidden flag in the same command
 * whatever those say, so nothing it brings into being is visible before a
 * human looks at it (ADR-199).
 *
 * A page is copied without its subpages, always. Core would hide only the
 * top page of a copied branch and leave every copied subpage as visible as
 * its original, reachable under a hidden parent (ADR-199).
 *
 * What it refuses, and why:
 *
 * - **Any table but `pages` and `tt_content`** (ADR-198).
 * - **A translation.** A connected translation is copied with its
 *   default-language record; a page translation with its page. The refusal
 *   names the record to copy instead.
 * - **A site root**, which would become a second root without a site.
 * - **A standalone element in a language whose connected translations
 *   already sit on the target page** — the page module's "Inconsistent content
 *   detected" (ADR-193) — unless the page's TSconfig opts in to that.
 * - **A copy the acting user may not make**: `PAGE_SHOW` on the source page,
 *   the record-level rights on the source, access to the language of every
 *   translation that is copied along, `CONTENT_EDIT` on the target page of an
 *   element or `PAGE_NEW` on the target parent of a page, and the field-level
 *   grant for the columns the tool sets on the copy.
 * - **A draft workspace and a process without a backend environment**, through
 *   {@see WritesThroughDataHandlerTrait}.
 */
final readonly class CopyRecordTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, RecordCreatorInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;
    // Table, language, translations and record-level rights of an existing row.
    use ActsOnAnExistingRecordTrait;

    /**
     * One string for "no such record", "deleted" and "you may not copy it or
     * copy it there", so a refusal never confirms that a uid exists.
     */
    private const NOT_PERMITTED = 'Record not found or not permitted.';

    private const PAGES_TABLE = 'pages';

    private const CONTENT_TABLE = 'tt_content';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'copy_record',
            'Copy ONE content element to a page and column, or ONE page (without its subpages) under a parent page. '
            . 'The copy is always HIDDEN, so a human must review and unhide it. Core copies the translations of a '
            . "default-language record along, and a page's content with the page. Writes through the TYPO3 "
            . 'DataHandler as the acting backend user, in the live workspace only.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'enum'        => self::EXISTING_RECORD_TABLES,
                        'description' => 'tt_content to copy a content element, pages to copy a page.',
                    ],
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the single record to copy.',
                    ],
                    'target_page' => [
                        'type'        => 'integer',
                        'description' => 'For tt_content: the page the copy goes on. For pages: the parent page the copy '
                            . 'goes under.',
                    ],
                    'after_uid' => [
                        'type'        => 'integer',
                        'description' => 'Place the copy directly after this record of the same table: a content '
                            . 'element on the target page in the same language, or a page under the target parent. Omit '
                            . 'to place it first.',
                    ],
                    'column' => [
                        'type'        => 'integer',
                        'description' => 'tt_content only: the backend layout column (colPos) of the copy. Omit to keep '
                            . 'the source\'s column, or to adopt the column of "after_uid".',
                    ],
                ],
                'required' => ['table', 'uid', 'target_page'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->writableActingUser($context, self::PAGES_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $update = [$plan['hiddenColumn'] => 1];
        if ($plan['table'] === self::CONTENT_TABLE) {
            $update['colPos'] = $plan['column'];
        }

        // Two preferences of the acting user decide what core's copy does, and
        // both are pinned for this one call and put back, in memory only — the
        // preferences are never written. `copyLevels` is the depth of a page
        // copy in TYPO3 14 (13 reads the DataHandler's `copyTree`, which is 0
        // unless set); `neverHideAtCopy` would switch core's own hiding off.
        $preferences = [];
        foreach (['copyLevels', 'neverHideAtCopy'] as $preference) {
            $preferences[$preference] = $user->uc[$preference] ?? null;
            $user->uc[$preference]    = 0;
        }

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], [$plan['table'] => [$plan['uid'] => ['copy' => [
                'action' => 'paste',
                'target' => $plan['destination'],
                'update' => $update,
            ]]]], $user);
            $dataHandler->process_cmdmap();
        } finally {
            foreach ($preferences as $preference => $value) {
                if ($value === null) {
                    unset($user->uc[$preference]);
                } else {
                    $user->uc[$preference] = $value;
                }
            }
        }

        $copied = $dataHandler->copyMappingArray_merged[$plan['table']] ?? null;
        $newUid = is_array($copied) ? self::toInt($copied[$plan['uid']] ?? 0) : 0;
        // Every row of the table core copied: the copy and the translations it
        // placed. The paste `update` reaches the copy only; the translations
        // get the hidden flag from core's `hideAtCopy`, which page TSconfig
        // `disableHideAtCopy` switches off. So each one is hidden below.
        $rows = [];
        foreach (is_array($copied) ? $copied : [] as $copyUid) {
            if (self::toInt($copyUid) > 0) {
                $rows[] = self::toInt($copyUid);
            }
        }

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $newUid > 0
                ? ToolResult::error($refused->content . ' ' . $this->takenBack($plan['table'], $newUid, $rows, $user))
                : $refused;
        }

        if ($newUid < 1) {
            return ToolResult::error(
                'The copy was not made, and the DataHandler reported no error. The acting backend user is most likely '
                . 'missing a permission core asks for on the source or the target.',
            );
        }

        $translations = array_values(array_diff($rows, [$newUid]));
        $this->hideAll($plan['table'], $plan['hiddenColumn'], $translations, $user);

        // Read back before reporting success. A copy that landed visible — the
        // copy itself or one of its translations — is the one outcome this tool
        // exists to prevent; a copy in the wrong place is not the copy the
        // approver agreed to. Either is taken back.
        $copy  = $this->fetchRowByUid($plan['table'], $newUid);
        $wrong = [];
        if ($copy === null || self::toInt($copy['pid'] ?? 0) !== $plan['targetPage']) {
            $wrong[] = 'it is not on the target';
        }

        if ($copy === null || self::toInt($copy[$plan['hiddenColumn']] ?? 0) !== 1) {
            $wrong[] = 'it is not hidden';
        }

        if ($plan['table'] === self::CONTENT_TABLE && ($copy === null || self::toInt($copy['colPos'] ?? -1) !== $plan['column'])) {
            $wrong[] = 'it is not in the column asked for';
        }

        foreach ($translations as $translationUid) {
            $translation = $this->fetchRowByUid($plan['table'], $translationUid, 'uid', $plan['hiddenColumn']);
            if ($translation !== null && self::toInt($translation[$plan['hiddenColumn']] ?? 0) !== 1) {
                $wrong[] = sprintf('its translation [%d] is not hidden', $translationUid);
            }
        }

        if ($wrong !== []) {
            return ToolResult::error(sprintf(
                'The copy %s [%d] was made but %s. %s',
                $plan['table'],
                $newUid,
                implode(' and ', $wrong),
                $this->takenBack($plan['table'], $newUid, $rows, $user),
            ));
        }

        return ToolResult::text(sprintf(
            'Copied %s [%d] "%s" to hidden %s [%d] "%s" %s [%d]%s%s. It is not visible until a human unhides it.',
            $plan['table'],
            $plan['uid'],
            $this->excerpt($plan['label']),
            $plan['table'],
            $newUid,
            $this->excerpt($this->labelOf($plan['table'], $copy ?? [])),
            $plan['table'] === self::PAGES_TABLE ? 'under page' : 'on page',
            $plan['targetPage'],
            $plan['table'] === self::CONTENT_TABLE ? sprintf(', column %d', $plan['column']) : '',
            $translations === [] ? '' : sprintf(', with %d hidden translation(s)', count($translations)),
        ))->withWriteTarget(new RecordReference($plan['table'], $newUid), WriteKind::CREATED);
    }

    /**
     * What the copy brings into being, as the approver reads it (ADR-136).
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

        $lines = [sprintf(
            'Copy %s [%d] "%s", language %d',
            $plan['table'],
            $plan['uid'],
            $this->excerpt($plan['label']),
            $plan['language'],
        )];

        if ($plan['table'] === self::PAGES_TABLE) {
            $lines[] = sprintf(
                'to: under page [%d] "%s", %s',
                $plan['targetPage'],
                $this->excerpt($plan['targetTitle']),
                $plan['afterUid'] > 0 ? sprintf('directly after page [%d] "%s"', $plan['afterUid'], $this->excerpt($plan['afterLabel'])) : 'first',
            );
            $lines[] = sprintf(
                'with the %d content element(s) on it and the records of the other tables core copies with a page; '
                . 'without its subpages',
                $plan['contentCount'],
            );
        } else {
            $lines[] = sprintf(
                'to: page [%d] "%s", column %d, %s',
                $plan['targetPage'],
                $this->excerpt($plan['targetTitle']),
                $plan['column'],
                $plan['afterUid'] > 0 ? sprintf('directly after element [%d] "%s"', $plan['afterUid'], $this->excerpt($plan['afterLabel'])) : 'first in the column',
            );
        }

        if ($plan['translations'] > 0) {
            // Which translations core copies is core's decision
            // (DataHandler::copyL10nOverlayRecords()), and it differs by
            // version: TYPO3 14 and the current 13.4 releases copy only into a
            // site that has the language and log a refusal for one they cannot
            // place, which takes the copy back; outside a site they copy none.
            // Earlier 13.4 releases have no site check and drop an element
            // translation the target page is not translated into in silence.
            // Whatever core copied is hidden and counted in the answer.
            $lines[] = sprintf(
                'with its %d translation(s), as far as core copies them to the target: current TYPO3 releases copy a '
                . 'translation only into a site that has its language%s, take the copy back where core refuses one, '
                . 'and copy none outside a site; the answer says how many were copied',
                $plan['translations'],
                $plan['table'] === self::PAGES_TABLE ? '' : ' and onto a target page translated into it',
            );
        }

        $lines[] = 'visibility: the copy and every copied translation are hidden — a human must unhide them before anyone sees them';

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the page permissions, the record-level rights
        // and the field-level grants are the acting user's own, checked here and
        // enforced a second time by the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // A copy brings a record into being with no caller-supplied key: two
        // calls leave two copies. A reaped run that may already have copied
        // must fail terminally rather than copy again.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * The tables a copy lands in. Both are refused by name in
     * `create_record_draft` as well (ADR-197).
     */
    public function getCreatedTables(): array
    {
        return [self::PAGES_TABLE, self::CONTENT_TABLE];
    }

    /**
     * Everything the copy needs, resolved and authorised — or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:'pages'|'tt_content', uid:int, label:string, language:int, hiddenColumn:non-empty-string, targetPage:int, targetTitle:string, column:int, afterUid:int, afterLabel:string, translations:int, contentCount:int, destination:int}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['table', 'uid', 'target_page', 'after_uid', 'column'],
            'copies one content element or one page',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $table = $this->existingRecordTable($arguments);
        if ($table === null) {
            return 'Refused: "table" must be "pages" or "tt_content".';
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive uid of exactly one record.';
        }

        $targetUid = self::toInt($arguments['target_page'] ?? 0);
        if ($targetUid < 1) {
            return 'Refused: "target_page" must be the positive uid of exactly one page; this tool does not copy to the '
                . 'root level.';
        }

        if ($table === self::PAGES_TABLE && array_key_exists('column', $arguments)) {
            return 'Refused: "column" applies to tt_content only.';
        }

        $requestedColumn = array_key_exists('column', $arguments) ? self::toInt($arguments['column']) : null;
        if ($requestedColumn !== null && $requestedColumn < 0) {
            return 'Refused: "column" must be zero or a positive backend-layout column (colPos).';
        }

        $afterUid = self::toInt($arguments['after_uid'] ?? 0);
        if (array_key_exists('after_uid', $arguments) && $afterUid < 1) {
            return 'Refused: "after_uid" must be the positive uid of one record.';
        }

        if ($afterUid === $uid) {
            return 'Refused: "after_uid" cannot be the record being copied.';
        }

        $row        = $this->fetchRowByUid($table, $uid);
        $sourcePage = $row === null ? null : ($table === self::PAGES_TABLE ? $row : $this->fetchRowByUid(self::PAGES_TABLE, self::toInt($row['pid'] ?? 0)));
        $target     = $this->fetchRowByUid(self::PAGES_TABLE, $targetUid);
        if ($row === null || $sourcePage === null || $target === null
            || !$user->doesUserHaveAccess($sourcePage, Permission::PAGE_SHOW)
            || !$this->mayEditRecord($table, $row, $user)
            || !$user->doesUserHaveAccess($target, $table === self::PAGES_TABLE ? Permission::PAGE_NEW : Permission::CONTENT_EDIT)
        ) {
            return self::NOT_PERMITTED;
        }

        $language = $this->languageOf($table, $row);
        $parent   = $this->translationParentOf($table, $row);
        if ($parent > 0 || ($table === self::PAGES_TABLE && $language !== 0)) {
            return sprintf(
                'Refused: %s [%d] is a translation, and core copies a translation together with its default-language '
                . 'record. Copy %s [%d] instead.',
                $table,
                $uid,
                $table,
                $parent,
            );
        }

        // A page translation is no place for a record: content sits on the
        // default-language page, and a page goes under one.
        $targetLanguage = $this->languageOf(self::PAGES_TABLE, $target);
        if ($targetLanguage !== 0) {
            return sprintf(
                'Refused: page [%d] is a translation (language %d) of page [%d]. Give the default-language page as '
                . '"target_page".',
                $targetUid,
                $targetLanguage,
                $this->translationParentOf(self::PAGES_TABLE, $target),
            );
        }

        if ($table === self::PAGES_TABLE && (bool)($row['is_siteroot'] ?? false)) {
            return sprintf(
                'Refused: page [%d] is a site root. A copy would be a second root without a site, which is an '
                . "administrator's decision and not something this tool does.",
                $uid,
            );
        }

        $hiddenColumn = $this->disabledColumnOf($table);
        if ($hiddenColumn === null) {
            return sprintf('Refused: %s declares no hidden column in this installation, so a copy could not be hidden.', $table);
        }

        $translations = $language === 0 ? $this->translationsOf($table, $uid) : [];
        $refused      = [];
        foreach ($translations as $translation) {
            // The record-level rights, as core's copy asks them per row.
            if (!$this->mayEditRecord($table, $translation, $user)) {
                $refused[] = $this->languageOf($table, $translation);
            }
        }

        if ($refused !== []) {
            return sprintf(
                'Refused: %s [%d] has translations in language(s) %s which the acting backend user may not edit '
                . '(language, lock or content type), and core copies them with it. Nothing was written.',
                $table,
                $uid,
                implode(', ', array_unique($refused)),
            );
        }

        $afterLabel  = '';
        $afterColumn = null;
        if ($afterUid > 0) {
            $anchor = $this->fetchRowByUid($table, $afterUid);
            if ($anchor === null || self::toInt($anchor['pid'] ?? 0) !== $targetUid) {
                return sprintf(
                    'Refused: %s [%d] is not %s page [%d], so it cannot anchor the copy.',
                    $table,
                    $afterUid,
                    $table === self::PAGES_TABLE ? 'under' : 'on',
                    $targetUid,
                );
            }

            if ($this->languageOf($table, $anchor) !== $language) {
                return sprintf('Refused: %s [%d] is in a different language than the record being copied.', $table, $afterUid);
            }

            $afterLabel  = $this->labelOf($table, $anchor);
            $afterColumn = $table === self::CONTENT_TABLE ? self::toInt($anchor['colPos'] ?? 0) : null;
        }

        $column  = $requestedColumn ?? ($afterColumn ?? self::toInt($row['colPos'] ?? 0));
        $setsOwn = $table === self::CONTENT_TABLE ? [$hiddenColumn, 'colPos'] : [$hiddenColumn];
        $ungranted = $this->columnsTheUserMayNotSet($user, $table, $setsOwn);
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s, which the tool '
                . 'sets on the copy. Nothing was written.',
                implode(', ', array_map(static fn(string $column): string => $table . ':' . $column, $ungranted)),
            );
        }

        if ($table === self::CONTENT_TABLE && $language > 0
            && $this->holdsConnectedTranslations($targetUid, $language)
            && !$this->allowsInconsistentLanguageHandling($targetUid)
        ) {
            return sprintf(
                'Refused: page [%d] already holds connected translations in language %d, and a standalone element beside '
                . 'them makes the page module report "Inconsistent content detected" (ADR-193).',
                $targetUid,
                $language,
            );
        }

        return [
            'table'        => $table,
            'uid'          => $uid,
            'label'        => $this->labelOf($table, $row),
            'language'     => $language,
            'hiddenColumn' => $hiddenColumn,
            'targetPage'   => $targetUid,
            'targetTitle'  => self::toStr($target['title'] ?? ''),
            'column'       => $column,
            'afterUid'     => $afterUid,
            'afterLabel'   => $afterLabel,
            'translations' => count($translations),
            'contentCount' => $table === self::PAGES_TABLE ? $this->contentCountOn($uid) : 0,
            // The DataHandler's convention: a positive destination is the page
            // (or the parent of a page), a negative one "directly after the
            // record with that uid".
            'destination' => $afterUid > 0 ? -$afterUid : $targetUid,
        ];
    }

    /**
     * Set the hidden flag on copied rows that core left visible, in one
     * datamap under the acting user — whose grant for the column `plan()`
     * asked. What still did not take is caught by the read-back.
     *
     * @param 'pages'|'tt_content' $table
     * @param non-empty-string     $hiddenColumn
     * @param list<int>            $uids
     */
    private function hideAll(string $table, string $hiddenColumn, array $uids, BackendUserAuthentication $user): void
    {
        $datamap = [];
        foreach ($uids as $uid) {
            $row = $this->fetchRowByUid($table, $uid, 'uid', $hiddenColumn);
            if ($row !== null && self::toInt($row[$hiddenColumn] ?? 0) !== 1) {
                $datamap[$uid] = [$hiddenColumn => 1];
            }
        }

        if ($datamap === []) {
            return;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => $datamap], [], $user);
        $dataHandler->process_datamap();
    }

    /**
     * Take the copy back and say whether it is gone — the copy and every row
     * core copied with it, which core deletes together with it.
     *
     * @param 'pages'|'tt_content' $table
     * @param list<int>            $rows  every copied row of the table, the copy included
     */
    private function takenBack(string $table, int $newUid, array $rows, BackendUserAuthentication $user): string
    {
        $this->discard($table, $newUid, $user);

        $left = [];
        foreach ($rows === [] ? [$newUid] : $rows as $uid) {
            if ($this->fetchRowByUid($table, $uid, 'uid') !== null) {
                $left[] = sprintf('%s [%d]', $table, $uid);
            }
        }

        return $left === []
            ? 'The copy was deleted again.'
            : sprintf('The copy COULD NOT BE DELETED and may be visible — remove %s by hand.', implode(', ', $left));
    }

    /**
     * Take back a copy the tool could not vouch for, reporting whether it is
     * gone. Through the DataHandler under the same user, so the removal sits
     * in the history next to the copy.
     *
     * @param 'pages'|'tt_content' $table
     */
    private function discard(string $table, int $uid, BackendUserAuthentication $user): bool
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        return $this->fetchRowByUid($table, $uid, 'uid') === null;
    }

    /**
     * The undeleted content elements on a page, every language.
     */
    private function contentCountOn(int $pageUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONTENT_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return self::toInt($queryBuilder
            ->count('uid')
            ->from(self::CONTENT_TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                ...$this->liveVersionConstraints($queryBuilder, self::CONTENT_TABLE),
            )
            ->executeQuery()
            ->fetchOne());
    }

    /**
     * Whether the page holds at least one connected translation in the
     * language — the half of core's mixed-mode condition a standalone copy
     * would complete, counted as {@see CreateContentElementDraftTool} counts it
     * (ADR-193): deleted rows out, hidden rows in, every workspace.
     */
    private function holdsConnectedTranslations(int $pageUid, int $language): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONTENT_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return self::toInt($queryBuilder
            ->count('uid')
            ->from(self::CONTENT_TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($language, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('l18n_parent', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne()) > 0;
    }

    /**
     * Whether the page's TSconfig opts in to mixed translation modes, read as
     * the page module reads it (ADR-193).
     */
    private function allowsInconsistentLanguageHandling(int $pageUid): bool
    {
        $tsConfig  = BackendUtility::getPagesTSconfig($pageUid);
        $mod       = is_array($tsConfig['mod.'] ?? null) ? $tsConfig['mod.'] : [];
        $webLayout = is_array($mod['web_layout.'] ?? null) ? $mod['web_layout.'] : [];

        return (bool)($webLayout['allowInconsistentLanguageHandling'] ?? false);
    }
}
