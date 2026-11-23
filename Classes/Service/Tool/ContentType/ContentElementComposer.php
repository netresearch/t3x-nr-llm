<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\ContentType;

use Netresearch\NrLlm\Service\Tool\Builtin\ReadsContentTypeFormsTrait;
use Netresearch\NrLlm\Service\Tool\Builtin\WritesThroughDataHandlerTrait;
use Netresearch\NrLlm\Service\Tool\PageTsConfigReader;
use Netresearch\NrLlm\Service\Tool\PageTsConfigReaderInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The content-type rule of `create_content_element_draft` for callers outside
 * the tool loop (ADR-324): which content types a draft can fill, what a model
 * may put into one — its scalar columns and the records of its inline columns
 * — which of them a page and a user allow, and the datamap of one element with
 * its children after every value and grant was checked.
 *
 * nr_landingpage's Page Composer builds its sections through this, so the
 * assistant and the Page Composer apply one rule. The rule itself lives in
 * {@see ReadsContentTypeFormsTrait}; this class only exposes it.
 *
 * @api
 */
final readonly class ContentElementComposer
{
    use SafeCastTrait;
    use WritesThroughDataHandlerTrait;
    use ReadsContentTypeFormsTrait;

    private const TABLE = 'tt_content';

    public function __construct(
        private PageTsConfigReaderInterface $pageTsConfig = new PageTsConfigReader(),
    ) {}

    /**
     * The content types a draft can fill on this installation, in the order
     * the TCA declares them. Empty when no TCA is loaded.
     *
     * @return list<string>
     */
    public function offeredTypes(): array
    {
        return $this->availableTypes();
    }

    /**
     * One offered content type as a model reads it, or null when the type is
     * not offered: `{type, label, description, fields: {column: {type, label,
     * required, max?, items?, richText?}}, children: {column: {table,
     * maxItems, fields: {...}}}}`. Labels and the description are translated
     * with `$languageService` where given. With `$user`, the columns and child
     * columns that user may not set (exclude-field grants) are left out, and a
     * child-record column whose records the user may not create, so a model is
     * never offered what {@see self::dataMap()} would refuse. Keys may be added
     * in a later version; none is removed.
     *
     * @return array{type: string, label: string, description: string, fields: array<string, array<string, mixed>>, children: array<string, array{table: string, maxItems: int, fields: array<string, array<string, mixed>>}>}|null
     */
    public function describe(string $cType, ?LanguageService $languageService = null, ?BackendUserAuthentication $user = null): ?array
    {
        if (!in_array($cType, $this->availableTypes(), true)) {
            return null;
        }

        $translate = $languageService instanceof LanguageService
            ? static fn (string $label): string => str_starts_with($label, 'LLL:') ? $languageService->sL($label) : $label
            : static fn (string $label): string => $label;

        $described = $this->describeContentType($cType, $translate);
        if (!$user instanceof BackendUserAuthentication) {
            return $described;
        }

        foreach ($this->columnsTheUserMayNotSet($user, self::TABLE, array_keys($described['fields'])) as $column) {
            unset($described['fields'][$column]);
        }

        $collections = $this->childCollectionsOfType($cType);
        foreach ($described['children'] as $column => $children) {
            if (!$this->userMayWriteChildren($column, $collections[$column] ?? null, $user)) {
                unset($described['children'][$column]);

                continue;
            }

            foreach ($this->columnsTheUserMayNotSet($user, $children['table'], array_keys($children['fields'])) as $name) {
                unset($described['children'][$column]['fields'][$name]);
            }
        }

        return $described;
    }

    /**
     * The types among `$candidates` that a user may create on a page: those
     * the page's `TCEFORM.tt_content.CType.keepItems` and `removeItems` keep
     * (skipped for page 0, where no page is chosen yet), that the user's
     * `authMode` on `CType` allows, and — for a type with child records —
     * whose child tables and required child columns the user may write. The
     * offered-type rule is NOT applied: a candidate this class does not
     * describe passes when the page and the user allow it.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public function typesOfferedOnPage(int $pageUid, BackendUserAuthentication $user, array $candidates): array
    {
        $rules = [];
        if ($pageUid > 0) {
            $tsConfig = $this->pageTsConfig->forPage($pageUid, $user);
            $tceForm  = is_array($tsConfig['TCEFORM.'] ?? null) ? $tsConfig['TCEFORM.'] : [];
            $rules    = is_array($tceForm[self::TABLE . '.'] ?? null) ? $tceForm[self::TABLE . '.'] : [];
        }

        $offered = [];
        foreach ($candidates as $candidate) {
            if (($rules !== [] && $this->tceFormRuleRemoving($rules, 'CType', $candidate, $candidate) !== null)
                || ($this->cTypeHasAuthMode() && !$user->checkAuthMode(self::TABLE, 'CType', $candidate))
                || !$this->userMayWriteChildrenOf($candidate, $user)
            ) {
                continue;
            }

            $offered[] = $candidate;
        }

        return $offered;
    }

    /**
     * The datamap of one content element with its child records, or the
     * refusals that stop it — nothing is written here.
     *
     * `$fields` are further scalar columns of the type and `$children` the
     * records of its inline columns, `{column: [{column: value}]}`, both
     * checked as `create_content_element_draft` checks them: values against
     * the TCA, the exclude-field grants, `tables_modify` on the child tables,
     * `authMode`, and the page TSconfig of `$pageUid` for each column.
     * `$pid` is what the rows get (a positive page uid, or the placeholder of
     * a page in the same datamap); `$pageUid` is the existing page whose
     * TSconfig applies (for a page created in the same datamap, its parent).
     *
     * The result holds `tt_content` → `$placeholder` → the columns to merge
     * into the caller's row (fields, FlexForm defaults, inline columns), and
     * the child rows per table. The caller writes CType, header, position and
     * visibility itself, in the same datamap.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $children
     */
    public function dataMap(string $cType, string $placeholder, array $fields, array $children, int|string $pid, int $pageUid, int $language, BackendUserAuthentication $user): ContentElementDataMap
    {
        if (!in_array($cType, $this->availableTypes(), true)) {
            return ContentElementDataMap::refused(sprintf('Refused: "%s" is not a content type a draft can fill.', $cType));
        }

        $checkedFields = $this->collectFields(['fields' => $fields], $cType, $user);
        if (is_string($checkedFields)) {
            return ContentElementDataMap::refused($checkedFields);
        }

        $ungranted = $this->columnsTheUserMayNotSet($user, self::TABLE, array_keys($checkedFields));
        if ($ungranted !== []) {
            return ContentElementDataMap::refused(sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s.',
                implode(', ', array_map(static fn (string $column): string => self::TABLE . ':' . $column, $ungranted)),
            ));
        }

        $plan = $this->childrenPlan($cType, $children, $language, $user);
        if (is_string($plan)) {
            return ContentElementDataMap::refused($plan);
        }

        // In a draft workspace a child table core does not version would be
        // written live under a draft element (ADR-262).
        if ($user->workspace > 0) {
            foreach ($plan as $collection) {
                if (!$this->tableIsVersioned($collection['table'])) {
                    return ContentElementDataMap::refused(sprintf(
                        'Refused: the user works in a draft workspace, and the TCA of %s has no versioning or allows editing live in a workspace; its records cannot be drafted there.',
                        $collection['table'],
                    ));
                }
            }
        }

        if ($pageUid > 0) {
            $tsConfig = $this->pageTsConfig->forPage($pageUid, $user);
            $tceForm  = is_array($tsConfig['TCEFORM.'] ?? null) ? $tsConfig['TCEFORM.'] : [];
            $refusal  = $this->columnsTsConfigRefusal($tceForm, self::TABLE, $cType, $checkedFields, $this->columnsOfType($cType), $pageUid)
                ?? $this->childrenTsConfigRefusal($tceForm, $plan, $pageUid, $cType);
            if ($refusal !== null) {
                return ContentElementDataMap::refused($refusal);
            }
        }

        $row = $checkedFields;
        foreach ($this->flexDefaultsOfType($cType, $user) as $column => $defaults) {
            $row[$column] = $defaults;
        }

        $childMap = $this->childDataMap($plan, $pid, $language);
        foreach ($childMap['inline'] as $column => $placeholders) {
            $row[$column] = $placeholders;
        }

        return new ContentElementDataMap([self::TABLE => [$placeholder => $row], ...$childMap['rows']], []);
    }

    /**
     * Whether the user may write the records of every inline column of the
     * type: `tables_modify` on the child table, the exclude-field grant of
     * the inline column and of every required child column. True for a type
     * without child records.
     */
    private function userMayWriteChildrenOf(string $cType, BackendUserAuthentication $user): bool
    {
        foreach ($this->childCollectionsOfType($cType) as $column => $collection) {
            if (!$this->userMayWriteChildren($column, $collection, $user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the user may create the records of one child-record column:
     * `tables_modify` on the child table, the exclude-field grant of the inline
     * column and of every required child column, and `authMode` on the child
     * table's type field for the type a record gets — the checks
     * {@see self::dataMap()} runs.
     *
     * @param array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int}|null $collection
     */
    private function userMayWriteChildren(string $column, ?array $collection, BackendUserAuthentication $user): bool
    {
        if ($collection === null) {
            return false;
        }

        if (!$user->isAdmin() && !$user->check('tables_modify', $collection['table'])) {
            return false;
        }

        $required = array_keys(array_filter($collection['fields'], $this->isRequiredColumn(...)));
        if ($this->columnsTheUserMayNotSet($user, self::TABLE, [$column]) !== []
            || $this->columnsTheUserMayNotSet($user, $collection['table'], array_map(strval(...), $required)) !== []
        ) {
            return false;
        }

        return $collection['typeField'] === ''
            || !$this->columnHasAuthMode($collection['table'], $collection['typeField'])
            || $user->checkAuthMode($collection['table'], $collection['typeField'], $collection['typeValue']);
    }
}
