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
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Delete ONE page or content element, through the DataHandler, as the acting
 * backend user (ADR-198).
 *
 * The delete is the DataHandler's own: both tables declare a `delete` column,
 * so the row is flagged `deleted = 1` and stays recoverable from the recycler
 * and the history — nothing is removed from the database. What goes with it is
 * what goes with it in the page module, and the approval card counts it
 * before anything happens:
 *
 * - **A default-language record takes its translations along.** Their
 *   languages must all be the acting user's, or the call is refused — the
 *   DataHandler would delete what it may and leave the rest.
 * - **A page takes every record stored on it along**, and every subpage: core
 *   deletes a default-language page's whole branch, and has no switch to
 *   leave it. A page with subpages is therefore refused unless the call sets
 *   `include_subpages`, and the card names how many there are. The branch is
 *   bounded ({@see self::MAX_BRANCH_PAGES}); a larger one is not an act one
 *   approval card can describe.
 *
 * What it refuses, and why:
 *
 * - **Any table but `pages` and `tt_content`** (ADR-198).
 * - **A site root.** Deleting it takes a site offline; that is an
 *   administrator's decision in the site configuration, not an editorial one.
 * - **A record the acting user may not delete.** `PAGE_DELETE` on a page — on
 *   the default-language page for a page translation, as core asks — and on
 *   every subpage; `CONTENT_EDIT` on the page of a content element; the
 *   record-level rights ({@see ActsOnAnExistingRecordTrait::mayEditRecord()})
 *   on the record and every subpage. A missing record and a forbidden one
 *   return the same neutral string.
 * - **A draft workspace and a process without a backend environment**, through
 *   {@see WritesThroughDataHandlerTrait}.
 *
 * The card also names how many other records still point at the one being
 * deleted (the reference index), so a link or a shortcut that will break is
 * visible before the approval rather than after.
 */
final readonly class DeleteRecordTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;
    // Table, language, translations and record-level rights of an existing row.
    use ActsOnAnExistingRecordTrait;

    /**
     * One string for "no such record", "deleted" and "you may not delete it",
     * so a refusal never confirms that a uid exists.
     */
    private const NOT_PERMITTED = 'Record not found or not permitted.';

    private const PAGES_TABLE = 'pages';

    private const CONTENT_TABLE = 'tt_content';

    /**
     * The largest branch one call deletes, the page itself not counted. Core
     * would delete any size; an approval card that has to summarise hundreds
     * of pages no longer shows the approver what they approve.
     */
    private const MAX_BRANCH_PAGES = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'delete_record',
            'Delete ONE page or content element. The record is flagged deleted (recoverable from the recycler), '
            . 'and what core deletes with it goes too: the translations of a default-language record, and for a '
            . 'page every record on it and every subpage. A page with subpages is refused unless include_subpages '
            . 'is true. A site root is never deleted. Writes through the TYPO3 DataHandler as the acting backend '
            . 'user, in the live workspace only.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'enum'        => self::EXISTING_RECORD_TABLES,
                        'description' => 'pages for a page, tt_content for a content element.',
                    ],
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the single record to delete.',
                    ],
                    'include_subpages' => [
                        'type'        => 'boolean',
                        'description' => 'Pages only: true to delete a page that has subpages together with its whole '
                            . 'branch. Omit or false to have such a page refused.',
                    ],
                ],
                'required' => ['table', 'uid'],
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

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$plan['table'] => [$plan['uid'] => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        // Read back whatever the error log says. The DataHandler declines a
        // delete without always writing to `errorLog` — and it goes on after
        // refusing one translation, so a complaint does not mean nothing was
        // deleted. The answer reports what is actually gone and what is left.
        $survivors = [];
        foreach ([...$plan['subpages'], ...$plan['translations']] as [$table, $uid]) {
            if ($this->fetchRowByUid($table, $uid, 'uid') !== null) {
                $survivors[] = sprintf('%s [%d]', $table, $uid);
            }
        }

        $recordGone = $this->fetchRowByUid($plan['table'], $plan['uid'], 'uid') === null;
        $complaints = $dataHandler->errorLog === [] ? '' : ' TYPO3 reported: ' . $this->summariseErrors($dataHandler->errorLog);
        if (!$recordGone) {
            return ToolResult::error(sprintf(
                'The delete did not take: %s [%d] is still there%s.%s The acting backend user is most likely missing a '
                . 'permission core asks for.',
                $plan['table'],
                $plan['uid'],
                $survivors === [] ? '' : ', and so ' . (count($survivors) === 1 ? 'is ' : 'are ') . implode(', ', array_slice($survivors, 0, 10)),
                $complaints,
            ));
        }

        if ($survivors !== [] || $complaints !== '') {
            // The record IS deleted, so the answer names it as written even
            // though part of what should have gone with it did not.
            return ToolResult::text(sprintf(
                'Deleted %s [%d] "%s", but not completely:%s%s It is flagged deleted and can be restored from the recycler.',
                $plan['table'],
                $plan['uid'],
                $this->excerpt($plan['label']),
                $survivors === [] ? '' : ' ' . implode(', ', array_slice($survivors, 0, 10)) . ' ' . (count($survivors) === 1 ? 'is' : 'are') . ' still there.',
                $complaints,
            ))->withWriteTarget(new RecordReference($plan['table'], $plan['uid']), WriteKind::DELETED);
        }

        return ToolResult::text(sprintf(
            'Deleted %s [%d] "%s"%s. It is flagged deleted and can be restored from the recycler.',
            $plan['table'],
            $plan['uid'],
            $this->excerpt($plan['label']),
            $this->alongWith($plan),
        ))->withWriteTarget(new RecordReference($plan['table'], $plan['uid']), WriteKind::DELETED);
    }

    /**
     * The record and everything that goes with it, as the approver reads it
     * (ADR-136).
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
            'Delete %s [%d] "%s" on page [%d] "%s", language %d',
            $plan['table'],
            $plan['uid'],
            $this->excerpt($plan['label']),
            $plan['page'],
            $this->excerpt($plan['pageTitle']),
            $plan['language'],
        )];

        if ($plan['translations'] !== []) {
            $lines[] = sprintf(
                'with its %d translation(s): %s',
                count($plan['translations']),
                implode(', ', array_map(static fn(array $t): string => '[' . $t[1] . ']', $plan['translations'])),
            );
        }

        if ($plan['table'] === self::PAGES_TABLE) {
            if ($plan['language'] > 0) {
                $lines[] = sprintf(
                    'with the %d content element(s) in language %d on its default-language page, and every other record in that language there',
                    $plan['contentCount'],
                    $plan['language'],
                );
            } else {
                $lines[] = sprintf('with %d subpage(s)', count($plan['subpages']));
                $lines[] = sprintf('with %d content element(s) on the page(s), and every other record stored there', $plan['contentCount']);
            }
        }

        $lines[] = $plan['referencedBy'] === 0
            ? 'referenced from no other record'
            : sprintf('still referenced from %d other record(s) — those links or shortcuts will point at a deleted record', $plan['referencedBy']);
        $lines[] = 'recoverable: flagged deleted, restorable from the recycler';

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the delete permission and the record-level
        // rights are the acting user's own, checked here and enforced a second
        // time by the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // A delete converges: a repeat finds the record gone and deletes
        // nothing further. It fails with the neutral refusal instead of
        // succeeding again, which a reaped and requeued run may safely see.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * Everything the delete needs, resolved and authorised — or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:'pages'|'tt_content', uid:int, label:string, page:int, pageTitle:string, language:int, translations:list<array{non-empty-string, int}>, subpages:list<array{non-empty-string, int}>, contentCount:int, referencedBy:int}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['table', 'uid', 'include_subpages'],
            'deletes one page or content element',
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

        $includeSubpages = $arguments['include_subpages'] ?? false;
        if (!is_bool($includeSubpages)) {
            return 'Refused: "include_subpages" must be true or false.';
        }

        if ($includeSubpages && $table !== self::PAGES_TABLE) {
            return 'Refused: "include_subpages" applies to pages only.';
        }

        $row = $this->fetchRowByUid($table, $uid);
        if ($row === null) {
            return self::NOT_PERMITTED;
        }

        $language = $this->languageOf($table, $row);
        $parent   = $this->translationParentOf($table, $row);
        $page     = $table === self::PAGES_TABLE ? $row : $this->fetchRowByUid(self::PAGES_TABLE, self::toInt($row['pid'] ?? 0));
        // A page translation is deleted under the default-language page's
        // delete permission, as core's canDeletePage() asks it.
        $permissionPage = $table === self::PAGES_TABLE && $parent > 0 ? $this->fetchRowByUid(self::PAGES_TABLE, $parent) : $page;
        if ($page === null || $permissionPage === null
            || !$user->doesUserHaveAccess($permissionPage, $table === self::PAGES_TABLE ? Permission::PAGE_DELETE : Permission::CONTENT_EDIT)
            || !$this->mayEditRecord($table, $row, $user)
        ) {
            return self::NOT_PERMITTED;
        }

        if ($table === self::PAGES_TABLE && (bool)($row['is_siteroot'] ?? false)) {
            return sprintf(
                "Refused: page [%d] is a site root. Deleting it takes a site offline, which is an administrator's "
                . 'decision and not something this tool does.',
                $uid,
            );
        }

        $translations = [];
        if ($language === 0) {
            $refusedLanguages = [];
            foreach ($this->translationsOf($table, $uid) as $translation) {
                // The record-level rights, not only the language: core deletes
                // each translation under them and goes on when one is refused,
                // which would leave a half-done delete behind.
                if (!$this->mayEditRecord($table, $translation, $user)) {
                    $refusedLanguages[] = $this->languageOf($table, $translation);
                }

                $translations[] = [$table, self::toInt($translation['uid'] ?? 0)];
            }

            if ($refusedLanguages !== []) {
                return sprintf(
                    'Refused: %s [%d] has translations in language(s) %s which the acting backend user may not edit '
                    . '(language, lock or content type), and core deletes them with it. Nothing was written.',
                    $table,
                    $uid,
                    implode(', ', array_unique($refusedLanguages)),
                );
            }
        }

        $subpages = [];
        $pageUids = [self::toInt($page['uid'] ?? 0)];
        if ($table === self::PAGES_TABLE && $parent === 0) {
            $branch = $this->branchOf($uid);
            if ($branch === null) {
                return sprintf(
                    'Refused: page [%d] has more than %d subpages. A branch that large is not deleted through one '
                    . 'approval; delete it in the page tree.',
                    $uid,
                    self::MAX_BRANCH_PAGES,
                );
            }

            if ($branch !== [] && !$includeSubpages) {
                return sprintf(
                    'Refused: page [%d] has %d subpage(s), and core deletes a page together with its whole branch. '
                    . 'Set "include_subpages" to true to delete them with it, or move them away first.',
                    $uid,
                    count($branch),
                );
            }

            foreach ($branch as $subpage) {
                if (!$user->doesUserHaveAccess($subpage, Permission::PAGE_DELETE) || !$this->mayEditRecord(self::PAGES_TABLE, $subpage, $user)) {
                    return sprintf(
                        'Refused: the branch below page [%d] holds a page the acting backend user may not delete, and '
                        . 'core deletes the branch whole or not at all. Nothing was written.',
                        $uid,
                    );
                }

                $subpageUid = self::toInt($subpage['uid'] ?? 0);
                $subpages[] = [self::PAGES_TABLE, $subpageUid];
                $pageUids[] = $subpageUid;
            }

            $onThePages = $this->refuseWhatIsOnThePages($uid, $pageUids, $user);
            if ($onThePages !== null) {
                return $onThePages;
            }
        }

        return [
            'table'        => $table,
            'uid'          => $uid,
            'label'        => $this->labelOf($table, $row),
            'page'         => self::toInt($page['uid'] ?? 0),
            'pageTitle'    => self::toStr($page['title'] ?? ''),
            'language'     => $language,
            'translations' => $translations,
            'subpages'     => $subpages,
            // A page translation takes the content of its language on the
            // default-language page with it, as core's deleteSpecificPage()
            // deletes it.
            'contentCount' => match (true) {
                $table !== self::PAGES_TABLE => 0,
                $parent > 0                  => $this->contentCountOn([$parent], $language),
                default                      => $this->contentCountOn($pageUids),
            },
            'referencedBy' => $this->referencingRecordCount($table, $uid, [[$table, $uid], ...$translations, ...$subpages]),
        ];
    }

    /**
     * What went with the record, for the success line.
     *
     * @param array{translations:list<array{non-empty-string, int}>, subpages:list<array{non-empty-string, int}>} $plan
     */
    private function alongWith(array $plan): string
    {
        $parts = [];
        if ($plan['translations'] !== []) {
            $parts[] = sprintf('%d translation(s)', count($plan['translations']));
        }

        if ($plan['subpages'] !== []) {
            $parts[] = sprintf('%d subpage(s)', count($plan['subpages']));
        }

        return $parts === [] ? '' : ' with ' . implode(' and ', $parts);
    }

    /**
     * Every undeleted default-language page below a page, depth first — the
     * set core's `getSubPagesOfPage()` deletes with it — or null when it holds
     * more than {@see self::MAX_BRANCH_PAGES}.
     *
     * @return list<array<string, mixed>>|null
     */
    private function branchOf(int $uid): ?array
    {
        $branch  = [];
        $pending = [$uid];
        while ($pending !== []) {
            $parent = array_shift($pending);

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::PAGES_TABLE);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            /** @var list<array<string, mixed>> $children */
            $children = $queryBuilder
                ->select('*')
                ->from(self::PAGES_TABLE)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parent, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('uid')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($children as $child) {
                $branch[]  = $child;
                $pending[] = self::toInt($child['uid'] ?? 0);
                if (count($branch) > self::MAX_BRANCH_PAGES) {
                    return null;
                }
            }
        }

        return $branch;
    }

    /**
     * The undeleted content elements on the given pages — every language, or
     * the one given.
     *
     * @param list<int> $pageUids
     */
    private function contentCountOn(array $pageUids, ?int $language = null): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONTENT_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $constraints = [$queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY))];
        if ($language !== null) {
            $constraints[] = $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($language, Connection::PARAM_INT));
        }

        return self::toInt($queryBuilder
            ->count('uid')
            ->from(self::CONTENT_TABLE)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchOne());
    }

    /**
     * The refusal for what core would delete from the pages without the
     * acting user's rights, or null.
     *
     * Core deletes every record stored on a deleted page, in every language,
     * without asking for each one; before it starts, it refuses a non-admin
     * whose `tables_modify` misses one of their tables
     * ({@see DataHandler::canDeletePage()}) and whose languages miss one of
     * the pages' translations. The tool asks the same, and one thing more
     * (ADR-198): the languages of the content elements on those pages, which
     * core deletes without a language check.
     *
     * @param list<int> $pageUids the page and its branch
     */
    private function refuseWhatIsOnThePages(int $uid, array $pageUids, BackendUserAuthentication $user): ?string
    {
        if ($user->isAdmin()) {
            return null;
        }

        foreach ($pageUids as $pageUid) {
            foreach ($this->translationsOf(self::PAGES_TABLE, $pageUid) as $translation) {
                if (!$this->mayEditRecord(self::PAGES_TABLE, $translation, $user)) {
                    return sprintf(
                        'Refused: a page to be deleted with page [%d] has a translation in language %d the acting backend '
                        . 'user may not edit, and core deletes it with the page. Nothing was written.',
                        $uid,
                        $this->languageOf(self::PAGES_TABLE, $translation),
                    );
                }
            }
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONTENT_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $languages = $queryBuilder
            ->select('sys_language_uid')
            ->from(self::CONTENT_TABLE)
            ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)))
            ->groupBy('sys_language_uid')
            ->executeQuery()
            ->fetchFirstColumn();
        foreach ($languages as $language) {
            if (!$user->checkLanguageAccess(self::toInt($language))) {
                return sprintf(
                    'Refused: page [%d] or its branch holds content in language %d, which the acting backend user may not '
                    . 'edit, and core deletes it with the page. Nothing was written.',
                    $uid,
                    self::toInt($language),
                );
            }
        }

        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        foreach ($tca as $table => $definition) {
            $table = self::toStr($table);
            $ctrl  = is_array($definition) && is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
            if ($table === '' || $table === self::PAGES_TABLE
                || ($user->check('tables_modify', $table) && !(bool)($ctrl['readOnly'] ?? false))
            ) {
                continue;
            }

            if ($this->holdsRecordsOn($table, $pageUids)) {
                return sprintf(
                    'Refused: page [%d] or its branch holds records of %s, which the acting backend user may not modify, '
                    . 'and core refuses to delete a page with such records on it. Nothing was written.',
                    $uid,
                    $table,
                );
            }
        }

        return null;
    }

    /**
     * Whether any undeleted record of the table is stored on the pages. A
     * table that cannot be queried holds none as far as this check goes; the
     * DataHandler asks the same again and refuses the delete.
     *
     * @param list<int> $pageUids
     */
    private function holdsRecordsOn(string $table, array $pageUids): bool
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            return self::toInt($queryBuilder
                ->count('uid')
                ->from($table)
                ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchOne()) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * How many OTHER live records the reference index says point at this
     * one — a link, a shortcut, a relation — leaving out the records that are
     * deleted together with it.
     *
     * @param list<array{non-empty-string, int}> $goingAlong
     */
    private function referencingRecordCount(string $table, int $uid, array $goingAlong): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');

        $rows = $queryBuilder
            ->select('tablename', 'recuid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('ref_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $excluded = array_map(static fn(array $record): string => $record[0] . ':' . $record[1], $goingAlong);
        $sources  = [];
        foreach ($rows as $row) {
            $source = self::toStr($row['tablename'] ?? '') . ':' . self::toInt($row['recuid'] ?? 0);
            if (!in_array($source, $excluded, true)) {
                $sources[$source] = true;
            }
        }

        return count($sources);
    }
}
