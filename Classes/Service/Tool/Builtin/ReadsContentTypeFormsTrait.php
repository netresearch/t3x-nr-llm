<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use TYPO3\CMS\Backend\Form\Utility\FormEngineUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * How a content element's form is read and a value for one of its columns is
 * checked — the rules ADR-196 wrote for {@see CreateContentElementDraftTool},
 * shared with {@see UpdateContentElementTool} (ADR-198) so both writers apply
 * ONE answer to "which types, which columns, which values".
 *
 * Moved here unchanged from the creating tool when the updating one arrived:
 * the exclusion rule for content types, the reading of a type's form with its
 * `columnsOverrides`, the classification of a column as fillable, left empty
 * or excluding, the value checks that refuse what the DataHandler would bend
 * in silence, the page TSconfig (`TCEFORM`) readers, the field-level grant
 * question and the read-back comparison. What each tool does with the answers
 * — which columns are arguments of their own, what it writes itself, what it
 * reports — stays in the tool.
 *
 * The consuming class must provide `self::toStr()` (via
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait}), a `TABLE` constant naming
 * `tt_content`, and `tcaColumnsFor()` (via {@see WritesThroughDataHandlerTrait}).
 */
trait ReadsContentTypeFormsTrait
{
    /**
     * Content types no installation may offer through this tool, whatever
     * their form holds (ADR-196): the legacy plugin element, raw HTML, a
     * record shortcut, a divider — and, by prefix, every menu. Their payload
     * references records or pages, or runs code.
     */
    private const DENIED_TYPES = ['list', 'html', 'shortcut', 'div'];

    private const DENIED_TYPE_PREFIX = 'menu_';

    /**
     * The `CType` item groups plugins register in: `plugins`, the default of
     * `registerPlugin()`, and `forms`, where core registers indexed_search,
     * felogin and form. Since TYPO3 v13 a plugin is a content type of its own,
     * and one registered without a FlexForm carries the scalar form
     * `addPlugin()` copies from `header` — so its columns do not mark it. An
     * Extbase plugin is also known by its registration, whatever its group
     * ({@see self::registeredPluginSignatures()}).
     */
    private const DENIED_ITEM_GROUPS = ['plugins', 'forms'];

    /**
     * The fillable TCA types for which FormEngine lets page TSconfig set
     * `config.readOnly` — its override matrix in typo3/cms-backend 14.3.7
     * ({@see FormEngineUtility}) lists no `radio`.
     */
    private const READ_ONLY_OVERRIDABLE_TYPES = ['input', 'text', 'select', 'check', 'number', 'datetime', 'color', 'email'];

    /**
     * TCA column types a model may fill through `fields`. A `select` counts
     * only with static items and no `foreign_table` — see {@see self::columnKind()}.
     */
    private const FILLABLE_COLUMN_TYPES = ['input', 'text', 'select', 'check', 'number', 'datetime', 'radio', 'color', 'email'];

    /**
     * Column types that leave a type offered and are never filled: the
     * relations core attaches to prose — `categories` on every element,
     * `header_link` in the header palette, `assets` on `textmedia` — and the
     * language selector. A draft leaves them empty; a later tool or a human
     * fills them. Every other non-fillable type excludes the type it sits in.
     */
    private const UNFILLED_COLUMN_TYPES = ['file', 'category', 'link', 'language'];

    /**
     * Columns of every element this tool sets itself or must never set:
     * identity, position, visibility, publication, audience and translation
     * topology (ADR-135's exclusions for pages, by analogy). Never a `fields`
     * key and never a reason to exclude a type. `t3ver_*` is matched by prefix.
     */
    private const SYSTEM_COLUMNS = [
        'uid', 'pid', 'CType', 'colPos', 'sorting', 'tstamp', 'crdate', 'deleted',
        'sys_language_uid', 'l18n_parent', 'l10n_source', 'l18n_diffsource',
        'hidden', 'starttime', 'endtime', 'fe_group', 'editlock',
    ];

    private const SYSTEM_COLUMN_PREFIX = 't3ver_';

    /** Upper bound for an `input` column whose TCA declares no `max`; the usual column is `varchar(255)`. */
    private const MAX_INPUT_LENGTH = 255;

    /**
     * Upper bound for the body. The column is `text` and the TCA declares no
     * `max`, so nothing else bounds a model-chosen argument — and a drafted
     * element is a paragraph or two, not a document.
     */
    private const MAX_BODY_LENGTH = 20000;

    /**
     * The path of the `keepItems` or `removeItems` rule that takes `$value`
     * out of the column's items for the type, or null when none does. A
     * `types.<CType>.` key overrides the column's own, as FormEngine merges
     * them; a `keepItems` that is set but empty keeps nothing.
     *
     * @param array<array-key, mixed> $rules the page's `TCEFORM.tt_content.`
     */
    private function tceFormRuleRemoving(array $rules, string $column, string $type, string $value): ?string
    {
        [$columnRules, $typeRules] = $this->tceFormRulesOf($rules, $column, $type);
        $prefix                    = 'TCEFORM.' . self::TABLE . '.' . $column . '.';

        foreach (['keepItems', 'removeItems'] as $key) {
            $list = array_key_exists($key, $typeRules) ? $typeRules[$key] : ($columnRules[$key] ?? null);
            if (!is_string($list)) {
                continue;
            }

            $items   = GeneralUtility::trimExplode(',', $list, true);
            $removed = $key === 'keepItems' ? !in_array($value, $items, true) : in_array($value, $items, true);
            if ($removed) {
                return $prefix . (array_key_exists($key, $typeRules) ? 'types.' . $type . '.' : '') . $key;
            }
        }

        return null;
    }

    /**
     * The path of the `disabled` rule that hides the column for the type, or
     * null when it is shown.
     *
     * @param array<array-key, mixed> $rules the page's `TCEFORM.tt_content.`
     */
    private function tceFormDisabling(array $rules, string $column, string $type): ?string
    {
        [$columnRules, $typeRules] = $this->tceFormRulesOf($rules, $column, $type);
        $fromType                  = array_key_exists('disabled', $typeRules);
        if (!(bool)($fromType ? $typeRules['disabled'] : ($columnRules['disabled'] ?? false))) {
            return null;
        }

        return 'TCEFORM.' . self::TABLE . '.' . $column . '.' . ($fromType ? 'types.' . $type . '.' : '') . 'disabled';
    }

    /**
     * The path of the `config.readOnly` rule that makes the column read-only
     * for the type, or null when it is editable. FormEngine takes the key
     * only for the TCA types its override matrix lists
     * ({@see FormEngineUtility::overrideFieldConf()}),
     * and so does this — a `radio` is not among them.
     *
     * @param array<array-key, mixed> $rules the page's `TCEFORM.tt_content.`
     */
    private function tceFormReadOnly(array $rules, string $column, string $type, string $tcaType): ?string
    {
        if (!in_array($tcaType, self::READ_ONLY_OVERRIDABLE_TYPES, true)) {
            return null;
        }

        [$columnRules, $typeRules] = $this->tceFormRulesOf($rules, $column, $type);
        $columnConfig              = is_array($columnRules['config.'] ?? null) ? $columnRules['config.'] : [];
        $typeConfig                = is_array($typeRules['config.'] ?? null) ? $typeRules['config.'] : [];
        $fromType                  = array_key_exists('readOnly', $typeConfig);
        if (!(bool)($fromType ? $typeConfig['readOnly'] : ($columnConfig['readOnly'] ?? false))) {
            return null;
        }

        return 'TCEFORM.' . self::TABLE . '.' . $column . '.' . ($fromType ? 'types.' . $type . '.' : '') . 'config.readOnly';
    }

    /**
     * The column's own TCEFORM rules and those for the type, apart.
     *
     * @param array<array-key, mixed> $rules
     *
     * @return array{array<array-key, mixed>, array<array-key, mixed>}
     */
    private function tceFormRulesOf(array $rules, string $column, string $type): array
    {
        $columnRules = is_array($rules[$column . '.'] ?? null) ? $rules[$column . '.'] : [];
        $types       = is_array($columnRules['types.'] ?? null) ? $columnRules['types.'] : [];
        $typeRules   = is_array($types[$type . '.'] ?? null) ? $types[$type . '.'] : [];

        return [$columnRules, $typeRules];
    }

    /**
     * Whether `tt_content.CType` declares `authMode` — the condition under
     * which the DataHandler asks {@see BackendUserAuthentication::checkAuthMode()}.
     */
    private function cTypeHasAuthMode(): bool
    {
        $column = $this->tcaColumnsFor(self::TABLE)['CType'] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;

        return is_array($config) && self::toStr($config['authMode'] ?? '') !== '';
    }

    /**
     * The content types the live TCA declares that pass the exclusion rule
     * (ADR-196): not on the deny-list, not a plugin, a form of
     * their own, and no column in it whose payload is something other than
     * prose.
     *
     * Empty when no TCA is loaded — there is no list to fall back to, and the
     * refusal for a missing backend environment is the one that fires then.
     *
     * @return list<string>
     */
    private function availableTypes(): array
    {
        $column = $this->tcaColumnsFor(self::TABLE)['CType'] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;
        $items  = is_array($config) ? ($config['items'] ?? null) : null;
        if (!is_array($items)) {
            return [];
        }

        $available = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = $item['value'] ?? null;
            if (is_string($type) && $type !== '--div--' && $this->isOffered($type, self::toStr($item['group'] ?? ''))) {
                $available[] = $type;
            }
        }

        return $available;
    }

    /**
     * Whether one declared type passes the exclusion rule.
     *
     * The deny-list is asked first and by name, so `html` stays out of reach
     * on an installation where its form happens to be scalar; the plugin
     * registration and the item group are asked next, so a plugin whose form
     * is scalar stays out too. Then
     * every column of the type's form that is not a system column decides: one
     * excluding column excludes the type. A type without a form is excluded
     * too — nothing says what it holds.
     *
     * @param string $itemGroup the `group` of the type's `CType` item, '' when it has none
     */
    private function isOffered(string $type, string $itemGroup): bool
    {
        if (in_array($type, self::DENIED_TYPES, true) || str_starts_with($type, self::DENIED_TYPE_PREFIX)) {
            return false;
        }

        if (in_array($itemGroup, self::DENIED_ITEM_GROUPS, true)
            || in_array($type, $this->registeredPluginSignatures(), true)
        ) {
            return false;
        }

        $columns = $this->columnsOfType($type);
        if ($columns === []) {
            return false;
        }

        foreach ($columns as $name => $config) {
            if (!$this->isSystemColumn($name) && $this->columnKind($config) === 'excluding') {
                return false;
            }
        }

        return true;
    }

    /**
     * The `CType` values of every Extbase plugin the installation registers.
     *
     * `ExtensionUtility::configurePlugin()` records each plugin under
     * `EXTCONF.extbase.extensions.<ExtensionName>.plugins.<PluginName>`, with
     * the extension name in UpperCamelCase, and derives the content type as
     * `strtolower(<ExtensionName> . '_' . <PluginName>)` — read in
     * typo3/cms-extbase 14.3.7 and 13.4.35, where the keys and the
     * derivation are the same. `registerPlugin()` names the group the item
     * goes into, so a plugin registered outside `plugins` and `forms` is
     * still found here.
     *
     * @return list<string>
     */
    private function registeredPluginSignatures(): array
    {
        $confVars   = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $extConf    = is_array($confVars['EXTCONF'] ?? null) ? $confVars['EXTCONF'] : [];
        $extbase    = is_array($extConf['extbase'] ?? null) ? $extConf['extbase'] : [];
        $extensions = is_array($extbase['extensions'] ?? null) ? $extbase['extensions'] : [];

        $signatures = [];
        foreach ($extensions as $extensionName => $extension) {
            $plugins = is_array($extension) && is_array($extension['plugins'] ?? null) ? $extension['plugins'] : [];
            foreach (array_keys($plugins) as $pluginName) {
                $signatures[] = strtolower($extensionName . '_' . $pluginName);
            }
        }

        return $signatures;
    }

    /**
     * The columns of one type's form — its `showitem` with every `--palette--`
     * expanded, dividers and line breaks skipped, labels stripped — keyed by
     * name, each with the config the DataHandler will apply to it: the
     * column's own, overlaid with the type's `columnsOverrides`.
     *
     * Read at call time, after core's TcaPreparation has added the general,
     * language, hidden and access palettes to every `tt_content` type, so the
     * system columns are in here and are skipped by name where it matters.
     * A column the showitem names but the TCA does not define is left out.
     *
     * @return array<non-empty-string, array<array-key, mixed>>
     */
    private function columnsOfType(string $type): array
    {
        $tca   = $GLOBALS['TCA'] ?? null;
        $table = is_array($tca) && is_array($tca[self::TABLE] ?? null) ? $tca[self::TABLE] : null;
        if ($table === null) {
            return [];
        }

        $columns   = is_array($table['columns'] ?? null) ? $table['columns'] : [];
        $palettes  = is_array($table['palettes'] ?? null) ? $table['palettes'] : [];
        $types     = is_array($table['types'] ?? null) ? $table['types'] : [];
        $typeConf  = is_array($types[$type] ?? null) ? $types[$type] : [];
        $overrides = is_array($typeConf['columnsOverrides'] ?? null) ? $typeConf['columnsOverrides'] : [];

        $names = [];
        foreach (explode(',', self::toStr($typeConf['showitem'] ?? '')) as $part) {
            $pieces = explode(';', trim($part));
            $name   = trim($pieces[0]);
            if (in_array($name, ['', '--div--', '--linebreak--'], true)) {
                continue;
            }

            if ($name !== '--palette--') {
                $names[] = $name;

                continue;
            }

            $paletteKey = trim($pieces[2] ?? '');
            $palette    = is_array($palettes[$paletteKey] ?? null) ? $palettes[$paletteKey] : [];
            foreach (explode(',', self::toStr($palette['showitem'] ?? '')) as $paletteItem) {
                $paletteName = trim(explode(';', trim($paletteItem))[0]);
                if ($paletteName !== '' && $paletteName !== '--linebreak--') {
                    $names[] = $paletteName;
                }
            }
        }

        $result = [];
        foreach ($names as $name) {
            $column = $columns[$name] ?? null;
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            if (!is_array($config)) {
                continue;
            }

            $override       = is_array($overrides[$name] ?? null) ? ($overrides[$name]['config'] ?? null) : null;
            $result[$name]  = is_array($override) ? array_replace_recursive($config, $override) : $config;
        }

        return $result;
    }

    /**
     * What one column means for the type it sits in.
     *
     * `fillable`: a scalar a model may set through `fields`. `unfilled`: a
     * relation the draft leaves empty without excluding the type. `excluding`:
     * everything else — a FlexForm, inline children, a group, folder or
     * record-backed select, a slug, a password, and every type this tool does
     * not know, so a new TCA type fails closed rather than open.
     *
     * @param array<array-key, mixed> $config
     *
     * @return 'fillable'|'unfilled'|'excluding'
     */
    private function columnKind(array $config): string
    {
        $type = self::toStr($config['type'] ?? '');
        if ($type === 'select') {
            if (self::toStr($config['foreign_table'] ?? '') !== '') {
                return 'excluding';
            }

            return $this->staticItemValues($config) === [] || $this->keyRefusal($config) !== null ? 'unfilled' : 'fillable';
        }

        if (in_array($type, self::FILLABLE_COLUMN_TYPES, true)) {
            // A scalar the DataHandler would bend by rule — see
            // keyRefusal() — stays in the form and is never filled.
            return $this->keyRefusal($config) === null ? 'fillable' : 'unfilled';
        }

        return in_array($type, self::UNFILLED_COLUMN_TYPES, true) ? 'unfilled' : 'excluding';
    }

    /**
     * Why a column of a fillable TCA type is still never a `fields` key, or
     * null when it may be one.
     *
     * A column the TCA declares `readOnly` is refused first: the backend form
     * shows it without letting an editor change it, and the DataHandler does
     * not read the flag, so a draft would set what no editor can.
     *
     * Each further case is one the DataHandler bends in silence by a rule the draft
     * cannot vouch for: a `check` with several items is a bitmask, and `1`
     * would set its first bit only; a `check` with `eval`
     * `maximumRecordsChecked` or `maximumRecordsCheckedInPid` is unchecked
     * again once enough other records carry it; an `input` or `email` with
     * `eval` `unique` or `uniqueInPid` is rewritten to a value no other
     * record holds. The column does not exclude its type — the draft leaves
     * it at its default.
     *
     * @param array<array-key, mixed> $config
     */
    private function keyRefusal(array $config): ?string
    {
        $type  = self::toStr($config['type'] ?? '');
        $evals = GeneralUtility::trimExplode(',', self::toStr($config['eval'] ?? ''), true);

        if ((bool)($config['readOnly'] ?? false)) {
            return 'the backend form shows it read-only (TCA readOnly)';
        }

        if ($type === 'check') {
            $items = is_array($config['items'] ?? null) ? $config['items'] : [];
            if (count($items) > 1) {
                return 'a check with several items is a bitmask, and this tool sets 0 or 1 only';
            }

            if (array_intersect($evals, ['maximumRecordsChecked', 'maximumRecordsCheckedInPid']) !== []) {
                return 'TYPO3 unchecks it in silence once enough other records carry it (eval maximumRecordsChecked)';
            }
        }

        if (in_array($type, ['input', 'email'], true) && array_intersect($evals, ['unique', 'uniqueInPid']) !== []) {
            return 'TYPO3 rewrites a value another record already holds (eval unique)';
        }

        return null;
    }

    /**
     * The disabled column is asked under the name the installation gives it
     * (see {@see self::hiddenField()}), not only under the standard `hidden`.
     */
    private function isSystemColumn(string $name): bool
    {
        return in_array($name, self::SYSTEM_COLUMNS, true)
            || str_starts_with($name, self::SYSTEM_COLUMN_PREFIX)
            || $name === $this->hiddenField();
    }

    /**
     * The values a `select`, `radio` or `check` column declares as static
     * items. A divider is an entry of `items` and not a value. Items with
     * non-scalar values are left out.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     */
    private function staticItemValues(array $config): array
    {
        $values = [];
        foreach (is_array($config['items'] ?? null) ? $config['items'] : [] as $item) {
            if (!is_array($item) || !array_key_exists('value', $item) || $item['value'] === '--div--') {
                continue;
            }

            if (is_string($item['value']) || is_int($item['value'])) {
                $values[] = (string)$item['value'];
            }
        }

        return $values;
    }

    /**
     * One `fields` value, validated against the column's TCA type and WRAPPED
     * in a one-element list — or the refusal. Wrapped for the reason
     * {@see self::text()} wraps.
     *
     * Mirrors what the DataHandler checks, and refuses where it would silently
     * bend the value: a `select` value outside the static items would be
     * stored and shown as invalid; a `number` outside `range` would be clamped;
     * an invalid `email` would be emptied; an `input`, or a `text` without
     * the RTE, below `min` would be stored as ''; a nine-digit `color` would be cut to seven unless the
     * column declares `opacity`. A `datetime` is anything PHP reads — an
     * integer timestamp (seconds of the day on a `time` column) or an ISO 8601
     * date — and is handed over in the shape both cores store
     * ({@see self::datetimeForDataHandler()}).
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{string|int}|string
     */
    private function fieldValue(string $column, mixed $value, array $config): array|string
    {
        $type = self::toStr($config['type'] ?? '');

        if ($type === 'check') {
            if (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
                return [(int)(bool)$value];
            }

            return sprintf('Refused: the value for "%s" must be true, false, 0 or 1.', $column);
        }

        if ($type === 'number') {
            return $this->numberValue($column, $value, $config);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return sprintf('Refused: the value for "%s" must be a string.', $column);
        }

        $text = trim(self::toStr($value));
        if ($text === '' && (bool)($config['required'] ?? false)) {
            return sprintf('Refused: "%s" is required and must not be empty.', $column);
        }

        if ($type === 'select' || $type === 'radio') {
            $allowed = $this->staticItemValues($config);
            if (!in_array($text, $allowed, true)) {
                return sprintf(
                    'Refused: the value for "%s" must be one of: %s.',
                    $column,
                    implode(', ', array_map(static fn(string $item): string => '"' . $item . '"', $allowed)),
                );
            }

            return [$text];
        }

        if ($type === 'datetime') {
            if ($text === '') {
                return [''];
            }

            // An integer on a `time` column is seconds of the day to both
            // cores, not a Unix timestamp, and is handed over as it is.
            if ($this->isTimeOfDay($config) && MathUtility::canBeInterpretedAsInteger($text)) {
                return [(int)$text];
            }

            // ISO 8601's end of day, 24:00, is one PHP reads only with a
            // warning, so it is refused below like any invalid time — and on
            // a date the refusal says how to write it instead. The hour must
            // be 24 (after `T` or a space), so 10:24:00 gets no such advice,
            // and a time-of-day column has no next day to point to.
            $endOfDay = !$this->isTimeOfDay($config) && preg_match('/[T ]24:00/', $text) === 1;
            $unreadable = sprintf(
                'Refused: the value for "%s" must be a date or time the CMS can read, such as 2026-09-21 or '
                . '2026-09-21T14:30:00+02:00%s.',
                $column,
                $endOfDay ? '; write midnight at the end of a day as 00:00 of the next day' : '',
            );

            try {
                $moment = is_numeric($text) ? (new DateTimeImmutable())->setTimestamp((int)$text) : new DateTimeImmutable($text);
            } catch (Exception) {
                return $unreadable;
            }

            // PHP rolls a date that does not exist over into the next month
            // (2026-02-30 becomes 2026-03-02) and only records a warning; the
            // rolled-over moment would be stored and read back as correct.
            $problems = DateTimeImmutable::getLastErrors();
            if (is_array($problems) && ($problems['warning_count'] > 0 || $problems['error_count'] > 0)) {
                return $unreadable;
            }

            return [$this->datetimeForDataHandler($moment, $config)];
        }

        if ($type === 'email' && $text !== '' && !GeneralUtility::validEmail($text)) {
            return sprintf('Refused: the value for "%s" must be a valid e-mail address.', $column);
        }

        // The DataHandler cuts a colour to seven characters unless the column
        // declares `opacity`, so a nine-digit value would be stored shortened.
        if ($type === 'color' && $text !== '') {
            $opacity = (bool)($config['opacity'] ?? false);
            if (preg_match($opacity ? '/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/' : '/^#[0-9A-Fa-f]{6}$/', $text) !== 1) {
                return sprintf(
                    'Refused: the value for "%s" must be a colour such as #1a2b3c%s.',
                    $column,
                    $opacity ? ' or #1a2b3c80' : '',
                );
            }
        }

        // Below `min` the DataHandler stores '' instead of the value — for an
        // `input`, and for a `text` unless its RTE is enabled. A bound given
        // as a string is a bound to it, which casts.
        $min      = MathUtility::canBeInterpretedAsInteger($config['min'] ?? null) ? (int)$config['min'] : 0;
        $minHolds = $type === 'input' || ($type === 'text' && !(bool)($config['enableRichtext'] ?? false));
        if ($minHolds && $min > 0 && $text !== '' && mb_strlen($text) < $min) {
            return sprintf('Refused: the value for "%s" must be at least %d characters.', $column, $min);
        }

        $max = MathUtility::canBeInterpretedAsInteger($config['max'] ?? null) ? (int)$config['max'] : 0;
        $max = $max > 0 ? $max : ($type === 'text' ? self::MAX_BODY_LENGTH : self::MAX_INPUT_LENGTH);
        if (mb_strlen($text) > $max) {
            return sprintf('Refused: the value for "%s" exceeds %d characters.', $column, $max);
        }

        return [$text];
    }

    /**
     * A `datetime` value in the one shape both supported cores store without
     * reinterpreting it.
     *
     * A string is NOT that shape: 13.4's DataHandler reads a string as UTC
     * wall time and subtracts the server's offset from it, so a day given as
     * `2026-09-21` lands on the evening before on any server outside UTC;
     * 14.3 reads the offset. An integer is taken verbatim by both — a Unix
     * timestamp for a date or a moment, seconds of the day for a `time` or
     * `timesec` column, which 14.3 reads as exactly that. A column stored in
     * a native `dbType` takes unqualified local wall time, which 13.4 parses
     * as UTC and writes back with `gmdate()`, and 14.3 parses and writes in
     * the server's zone — the same string either way.
     *
     * @param array<array-key, mixed> $config
     */
    private function datetimeForDataHandler(DateTimeImmutable $moment, array $config): string|int
    {
        $local = $moment->setTimezone(new DateTimeZone(date_default_timezone_get()));

        if ($this->isNativeDateTime($config)) {
            return $local->format('Y-m-d H:i:s');
        }

        if ($this->isTimeOfDay($config)) {
            return (int)$local->format('H') * 3600 + (int)$local->format('i') * 60 + (int)$local->format('s');
        }

        return $moment->getTimestamp();
    }

    /**
     * A `fields` value as the approver reads it on the card.
     *
     * A `datetime` is handed to the DataHandler as an integer
     * ({@see self::datetimeForDataHandler()}), and a timestamp is not
     * readable; the card is the human gate (ADR-136), so it shows the moment
     * the integer stands for, in the server's zone — a time of day for
     * seconds of the day, a date and time for a timestamp. Every other value
     * is shown as it is handed over.
     *
     * @param array<array-key, mixed> $config
     */
    private function shownValue(string|int $value, array $config): string
    {
        if (!is_int($value) || self::toStr($config['type'] ?? '') !== 'datetime') {
            return self::toStr($value);
        }

        return $this->isTimeOfDay($config) ? gmdate('H:i:s', $value) : date('Y-m-d H:i:s', $value);
    }

    /**
     * Whether the column is stored in a native `dbType` — a `DATE`, `DATETIME`
     * or `TIME` column rather than an integer.
     *
     * @param array<array-key, mixed> $config
     */
    private function isNativeDateTime(array $config): bool
    {
        return in_array(self::toStr($config['dbType'] ?? ''), ['date', 'datetime', 'time'], true);
    }

    /**
     * Whether the column stores seconds of the day: a `time` or `timesec`
     * format in an integer column.
     *
     * @param array<array-key, mixed> $config
     */
    private function isTimeOfDay(array $config): bool
    {
        if ($this->isNativeDateTime($config)) {
            return false;
        }

        $format = self::toStr($config['format'] ?? 'datetime');

        return $format === 'time' || $format === 'timesec';
    }

    /**
     * A `number` value: a whole number unless the column declares
     * `format: decimal`, then with at most two decimal places, within the TCA
     * `range` where one is declared. The DataHandler would round a third
     * place away and clamp an out-of-range value in silence, and the
     * read-back would accept the first and blame a grant for the second.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{string|int}|string
     */
    private function numberValue(string $column, mixed $value, array $config): array|string
    {
        if (!is_int($value) && !is_float($value) && (!is_string($value) || !is_numeric(trim($value)))) {
            return sprintf('Refused: the value for "%s" must be a number.', $column);
        }

        $number  = is_string($value) ? (float)trim($value) : (float)$value;
        $decimal = self::toStr($config['format'] ?? 'integer') === 'decimal';
        if (!$decimal && floor($number) !== $number) {
            return sprintf('Refused: the value for "%s" must be a whole number.', $column);
        }

        // The DataHandler's own comparison (typo3/cms-core 14.3.7,
        // checkValueForNumber()): a decimal first rounded to the two places
        // it stores, then rounded up against the upper bound and down against
        // the lower, the bounds cast to an integer for a whole-number column —
        // and a value outside is clamped. A decimal inside a fractional bound
        // can still be clamped that way.
        $rounded = $decimal ? (float)number_format($number, 2, '.', '') : $number;
        $range   = is_array($config['range'] ?? null) ? $config['range'] : [];
        $lower   = $range['lower'] ?? null;
        if (is_numeric($lower) && floor($rounded) < ($decimal ? (float)$lower : (float)(int)$lower)) {
            return sprintf(
                'Refused: the value for "%s" must be at least %s%s.',
                $column,
                self::toStr($lower),
                $decimal ? '; the CMS compares it rounded down' : '',
            );
        }

        $upper = $range['upper'] ?? null;
        if (is_numeric($upper) && ceil($rounded) > ($decimal ? (float)$upper : (float)(int)$upper)) {
            return sprintf(
                'Refused: the value for "%s" must be at most %s%s.',
                $column,
                self::toStr($upper),
                $decimal ? '; the CMS compares it rounded up' : '',
            );
        }

        // The DataHandler stores two decimals and rounds a third away, so the
        // element would hold a value neither the model nor the approver named.
        if ($rounded !== $number) {
            return sprintf(
                'Refused: the value for "%s" has more than two decimal places, and the CMS stores it rounded to %s. '
                . 'Give it with at most two.',
                $column,
                number_format($number, 2, '.', ''),
            );
        }

        // Two decimals is what the DataHandler stores for a decimal column, so
        // the read-back compares like with like.
        return [$decimal ? number_format($number, 2, '.', '') : (int)$number];
    }

    /**
     * The columns among `$columns` this user may not write, because the TCA
     * marks them `exclude` and the user holds no `non_exclude_fields` grant —
     * the same question the DataHandler asks, through the same method, as
     * {@see UpdateFalAssetMetaTool} asks it. `exclude` is read as core's
     * schema reads it, as a boolean cast, so an extension's integer `1` counts.
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function fieldsTheUserMayNotWrite(BackendUserAuthentication $user, array $columns): array
    {
        $tcaColumns = $this->tcaColumnsFor(self::TABLE) ?? [];

        $ungranted = [];
        foreach ($columns as $column) {
            $definition = $tcaColumns[$column] ?? null;
            $excluded   = is_array($definition) && (bool)($definition['exclude'] ?? false);
            if ($excluded && !$user->check('non_exclude_fields', self::TABLE . ':' . $column)) {
                $ungranted[] = $column;
            }
        }

        return $ungranted;
    }

    /**
     * The columns set through `fields` — and the two text arguments, handed
     * in with them — whose stored value is not the one asked for.
     *
     * Compared as strings, which is how a check, a number and an integer
     * select item come back from the database; a decimal is compared as a
     * number, because the database renders `12.00` as it likes. Three kinds
     * are checked for presence rather than equality, because the DataHandler
     * rewrites them on purpose: a `datetime` is normalised to its `format` and
     * clamped to its `range`; a `text` column with `enableRichtext` passes
     * through the RTE transformation; an `input` column with an `eval` beyond
     * `trim` (`upper`, `lower`, `nospace`, `alphanum`, …) is
     * transformed by it. For those, a dropped column reads back as the
     * column's empty value, and that is what is tested.
     *
     * @param array<string, mixed>                $stored
     * @param array<non-empty-string, string|int> $fields
     *
     * @return list<string>
     */
    private function fieldsThatDidNotTake(array $stored, array $fields, string $type): array
    {
        $columns = $this->columnsOfType($type);

        $notTaken = [];
        foreach ($fields as $column => $value) {
            $config     = $columns[$column] ?? [];
            $tcaType    = self::toStr($config['type'] ?? '');
            $storedText = self::toStr($stored[$column] ?? '');

            if ($this->isRewrittenOnPurpose($tcaType, $config)) {
                // Midnight, as seconds of the day, and the epoch are both `0`
                // and read back exactly as an empty column does. A native
                // `time` column stores midnight as `00:00:00`, which core
                // keeps where it nulls the other native empty values, so it
                // is read as held — and on such a column without `nullable`
                // a dropped value reads the same and is read as held too.
                $asked = !in_array((string)$value, ['', '0'], true);
                $held  = !in_array($storedText, ['', '0', '0000-00-00', '0000-00-00 00:00:00'], true);
                if ($asked !== $held) {
                    $notTaken[] = $column;
                }

                continue;
            }

            if ($tcaType === 'number' && self::toStr($config['format'] ?? 'integer') === 'decimal') {
                if (!is_numeric($storedText) || number_format((float)$storedText, 2, '.', '') !== (string)$value) {
                    $notTaken[] = $column;
                }

                continue;
            }

            if ($storedText !== (string)$value) {
                $notTaken[] = $column;
            }
        }

        return $notTaken;
    }

    /**
     * Whether the DataHandler stores a column of this kind in a shape other
     * than the one handed over — see {@see self::fieldsThatDidNotTake()}.
     *
     * @param array<array-key, mixed> $config
     */
    private function isRewrittenOnPurpose(string $tcaType, array $config): bool
    {
        if ($tcaType === 'datetime') {
            return true;
        }

        if ($tcaType === 'text') {
            return (bool)($config['enableRichtext'] ?? false);
        }

        if ($tcaType === 'input') {
            $evals = array_filter(array_map(trim(...), explode(',', self::toStr($config['eval'] ?? ''))));

            return array_diff($evals, ['trim']) !== [];
        }

        return false;
    }

    /**
     * The name of the table's "hidden" column as the installation declares it.
     *
     * Read from the TCA rather than hardcoded because it is the field that
     * makes this a DRAFT tool: an installation that renamed it must not end up
     * with a visible element and a success message that claims otherwise.
     *
     * @return non-empty-string
     */
    private function hiddenField(): string
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[self::TABLE] ?? null) ? ($tca[self::TABLE]['ctrl'] ?? null) : null;
        $cols = is_array($ctrl) ? ($ctrl['enablecolumns'] ?? null) : null;
        $name = is_array($cols) ? ($cols['disabled'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'hidden';
    }
}
