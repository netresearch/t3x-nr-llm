<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ContentType\ProcessedSelectItems;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The names a computed select of a content element's child records takes
 * (ADR-328): what `create_content_element_draft` marks `~` in its description
 * without listing it (ADR-326), such as the icons of an icon group.
 *
 * The content types, the child-record columns, the grants and the items are
 * asked through the code the draft tool asks them through
 * ({@see ReadsContentTypeFormsTrait}, {@see ProcessedSelectItems}), as the
 * acting backend user. The call names no page, as in
 * {@see \Netresearch\NrLlm\Service\Tool\ContentType\ContentElementComposer::describe()}:
 * the page's TSconfig, the default one included, and a processor that reads the
 * page are left to the draft. The list can therefore hold a name the form of a
 * page does not show and the draft refuses.
 */
final readonly class ListContentElementChoicesTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;

    use SafeCastTrait;
    // The grant questions and the TCA reading the form rules need; nothing is
    // written.
    use WritesThroughDataHandlerTrait;
    use ReadsContentTypeFormsTrait;

    private const TABLE = 'tt_content';

    /** One string for "no acting user" and "may not create content elements". */
    private const NOT_PERMITTED = 'Not permitted: the acting backend user may not create content elements.';

    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 1000;

    /**
     * Below the 50,000 bytes {@see \Netresearch\NrLlm\Service\Tool\ToolResultBounder}
     * allows, whose cut would take the closing line with it.
     */
    private const MAX_NAME_BYTES = 32000;

    /** What the nearest names of all searches without a hit may take of the answer. */
    private const MAX_NEAR_BYTES = 6000;

    /** How many lists are sorted for nearest names in one answer, over all searches. */
    private const MAX_NEAR_LISTS = 20;

    /** What the lists that could not be computed may take of the answer. */
    private const MAX_FAILURE_BYTES = 4000;

    /** How many near names a search without a hit offers per list. */
    private const MAX_NEAR_NAMES = 10;

    /** How many comma-separated searches one call makes. */
    private const MAX_SEARCHES = 10;

    /** The longest of them that is looked for, in characters. */
    private const MAX_SEARCH_LENGTH = 60;

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_content_element_choices',
            'List the choices of a child-record column that create_content_element_draft marks with "~" (one of a computed '
            . 'list), such as the icons of an icon group, as the acting backend user may use them. The names are the '
            . 'values the draft takes. Use "search" instead of reading a long list. Where the choices depend on another '
            . "column of the record, they are listed per choice of it. The page's TSconfig is not applied here; the draft "
            . 'checks it.',
            [
                'type'       => 'object',
                'properties' => [
                    'type' => [
                        'type'        => 'string',
                        'description' => 'The content type (CType), e.g. "icon_group".',
                    ],
                    'children' => [
                        'type'        => 'string',
                        'description' => 'The child-record column of the type: <column> in "children.<column>[]".',
                    ],
                    'column' => [
                        'type'        => 'string',
                        'description' => 'The column of the child record whose choices are asked for.',
                    ],
                    'set' => [
                        'type'        => 'object',
                        'description' => 'Other selects of the same record the choices depend on, as {column: name}. Omit to '
                            . 'get the choices per set.',
                        'additionalProperties' => true,
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => sprintf('Words a name contains, whatever the case. Several searches are separated by a comma, at most %d, '
                            . 'e.g. "sun, bike, phone": a name is listed that contains every word of one of them.', self::MAX_SEARCHES),
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => sprintf('The most names to list: %d when omitted, at most %d.', self::DEFAULT_LIMIT, self::MAX_LIMIT),
                    ],
                ],
                'required' => ['type', 'children', 'column'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication || !$this->mayCreateContent($user)) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $type       = trim(self::toStr($arguments['type'] ?? ''));
        $children   = trim(self::toStr($arguments['children'] ?? ''));
        $column     = trim(self::toStr($arguments['column'] ?? ''));
        $collection = $this->collectionFor($type, $children, $column, $user);
        if (is_string($collection)) {
            return ToolResult::text($collection);
        }

        $values = $this->valuesOfSet($collection, $column, $arguments['set'] ?? null, $user);
        if (is_string($values)) {
            return ToolResult::text($values);
        }

        $searches = $this->searchesOf(self::toStr($arguments['search'] ?? ''));
        $limit    = min(self::MAX_LIMIT, max(1, self::toInt($arguments['limit'] ?? self::DEFAULT_LIMIT)));

        return $this->listed($column, $children, $type, $this->listsFor($collection, $column, $values, $user), $searches, $limit);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins: the type, the records and the column are asked
        // of the acting user's own grants, as the draft asks them.
        return false;
    }

    public function getGroup(): string
    {
        // Beside the page readers: the names are values of the form of a content
        // element, and the draft that takes them is editorial content too.
        return 'content';
    }

    private function mayCreateContent(BackendUserAuthentication $user): bool
    {
        return $user->isAdmin() || $user->check('tables_modify', self::TABLE);
    }

    private function mayUseType(string $type, BackendUserAuthentication $user): bool
    {
        return !$this->cTypeHasAuthMode() || $user->checkAuthMode(self::TABLE, 'CType', $type);
    }

    /**
     * Whether `authMode` leaves the user the value, as the draft asks it.
     */
    private function mayChoose(string $table, string $column, string $value, BackendUserAuthentication $user): bool
    {
        return $value === '' || !$this->columnHasAuthMode($table, $column) || $user->checkAuthMode($table, $column, $value);
    }

    /**
     * The child records the call asks about, or the answer that names what is
     * wrong: the type, the child-record column or the column is not there, is
     * not the user's, or has no computed choices.
     *
     * @return array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int}|string
     */
    private function collectionFor(string $type, string $children, string $column, BackendUserAuthentication $user): array|string
    {
        if (!in_array($type, $this->availableTypes(), true)) {
            return sprintf(
                'Unknown content type "%s". Content types with computed choices: %s.',
                $this->identifier($type),
                implode(', ', $this->typesWithChoices($user)) ?: 'none',
            );
        }

        if (!$this->mayUseType($type, $user)) {
            return sprintf('Not permitted: the acting backend user is not allowed content type "%s".', $type);
        }

        $collections = $this->childCollectionsOfType($type);
        $collection  = $collections[$children] ?? null;
        if ($collection === null) {
            return sprintf(
                'Unknown child-record column "%s" of content type "%s". Child-record columns: %s.',
                $this->identifier($children),
                $type,
                implode(', ', array_keys($collections)) ?: 'none',
            );
        }

        if (!$this->userMayWriteChildren($children, $collection, $user)) {
            return sprintf('Not permitted: the acting backend user may not create the records of "children.%s".', $children);
        }

        $choosable = $this->choosableColumns($collection, $user);
        if (!isset($collection['fields'][$column])) {
            return sprintf(
                'Unknown column "%s" of the records in "children.%s". Columns with computed choices: %s.',
                $this->identifier($column),
                $children,
                implode(', ', $choosable) ?: 'none',
            );
        }

        if ($this->columnsTheUserMayNotSet($user, $collection['table'], [$column]) !== []) {
            return sprintf('Not permitted: the acting backend user holds no field-level ("exclude field") grant for %s:%s.', $collection['table'], $column);
        }

        return in_array($column, $choosable, true) ? $collection : sprintf(
            '"%s" of the records in "children.%s" has no computed choices. Columns with computed choices: %s.',
            $column,
            $children,
            implode(', ', $choosable) ?: 'none',
        );
    }

    /**
     * The types with a computed select the user may fill.
     *
     * @return list<string>
     */
    private function typesWithChoices(BackendUserAuthentication $user): array
    {
        return array_values(array_filter(
            $this->availableTypes(),
            function (string $type) use ($user): bool {
                foreach ($this->mayUseType($type, $user) ? $this->childCollectionsOfType($type) : [] as $children => $collection) {
                    if ($this->userMayWriteChildren($children, $collection, $user) && $this->choosableColumns($collection, $user) !== []) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * The computed selects of the records that the user may set.
     *
     * @param array{table: non-empty-string, fields: array<non-empty-string, array<array-key, mixed>>} $collection
     *
     * @return list<string>
     */
    private function choosableColumns(array $collection, BackendUserAuthentication $user): array
    {
        $columns = array_keys(array_filter($collection['fields'], $this->isProcessedSelect(...)));

        return array_values(array_diff($columns, $this->columnsTheUserMayNotSet($user, $collection['table'], $columns)));
    }

    /**
     * The other selects of the records that the user may set and whose value
     * the draft knows when it computes the choices of `$column`: every static
     * one, and the computed ones before it in the form.
     *
     * @param array{table: non-empty-string, fields: array<non-empty-string, array<array-key, mixed>>} $collection
     *
     * @return list<non-empty-string>
     */
    private function otherSelects(array $collection, string $column, BackendUserAuthentication $user): array
    {
        $columns = [];
        $before  = true;
        foreach ($collection['fields'] as $name => $config) {
            if ($name === $column) {
                $before = false;
            } elseif (self::toStr($config['type'] ?? '') === 'select' && ($before || !$this->isProcessedSelect($config))) {
                $columns[] = $name;
            }
        }

        return array_values(array_diff($columns, $this->columnsTheUserMayNotSet($user, $collection['table'], $columns)));
    }

    /**
     * The stored values of the selects the call names, checked as the draft
     * checks them, or the answer that names the wrong one. Computed selects
     * last and in the form's order: the items of one can depend on another.
     *
     * @param array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int} $collection
     *
     * @return array<string, string|int>|string
     */
    private function valuesOfSet(array $collection, string $column, mixed $set, BackendUserAuthentication $user): array|string
    {
        $set ??= [];
        if (!is_array($set) || ($set !== [] && array_is_list($set))) {
            return 'Refused: "set" must be an object of column names to names, such as {"icon_set": "…"}.';
        }

        $selects = $this->otherSelects($collection, $column, $user);
        foreach (array_keys($set) as $key) {
            if (!in_array((string) $key, $selects, true)) {
                return sprintf(
                    'Unknown or not permitted: "%s" in "set" is not another select of these records the acting backend user may set. Selects: %s.',
                    $this->identifier((string) $key),
                    implode(', ', $selects) ?: 'none',
                );
            }
        }

        $named     = array_intersect_key($collection['fields'], $set);
        $processed = array_filter($named, $this->isProcessedSelect(...));
        $values    = [];
        foreach ([...array_diff_key($named, $processed), ...$processed] as $name => $config) {
            $checked = isset($processed[$name])
                ? $this->processedFieldValue($collection, $name, $set[$name], $values, 0, 0, [], $user)
                : $this->fieldValue($name, $set[$name], $config);
            if (is_string($checked)) {
                return $checked;
            }

            if (!$this->mayChoose($collection['table'], $name, (string) $checked[0], $user)) {
                return sprintf('Not permitted: the acting backend user is not allowed the value "%s" for "%s".', (string) $checked[0], $name);
            }

            $values[$name] = $checked[0];
        }

        return $values;
    }

    /**
     * The lists of choices, and the column they are listed by: one list for
     * the record the call describes or, where another select the call does not
     * name decides, one per choice of that select.
     *
     * @param array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int} $collection
     * @param array<string, string|int>                                                                                                                                                                                           $values
     *
     * @return array{by: string, lists: list<array{name: string, items: ProcessedSelectItems}>, others: list<string>, failures: list<string>}
     */
    private function listsFor(array $collection, string $column, array $values, BackendUserAuthentication $user): array
    {
        $items = $this->processedItems($collection, $column, $values, 0, 0, [], $user);
        $found = ['by' => '', 'lists' => [['name' => '', 'items' => $items]], 'others' => [], 'failures' => []];
        foreach ($this->otherSelects($collection, $column, $user) as $other) {
            if (array_key_exists($other, $values)) {
                continue;
            }

            $choices = array_values(array_filter(
                $this->choicesOf($collection, $other, $values, $user),
                fn (array $choice): bool => $this->mayChoose($collection['table'], $other, $choice['value'], $user),
            ));
            if (count($choices) > self::MAX_ITEM_SETS) {
                $found['others'][] = $other;

                continue;
            }

            $sets = $this->itemSets($collection, $column, $values, $other, $choices, $user);
            if (array_filter($sets, static fn (array $set): bool => $set['items']->failed === '' && $set['items']->names() !== $items->names()) === []) {
                // One list stands for all that could be computed, and a set
                // that could not stays named beside it.
                $found['failures'] = [...$found['failures'], ...$this->failuresOf($other, $sets)];

                continue;
            }

            if ($found['by'] === '') {
                $found['by']    = $other;
                $found['lists'] = $sets;
            } else {
                $found['others'][] = $other;
            }
        }

        return $found;
    }

    /**
     * @param array{by: string, lists: list<array{name: string, items: ProcessedSelectItems}>, others: list<string>, failures: list<string>} $found
     * @param list<string>                                                                                                                   $searches
     */
    private function listed(string $column, string $children, string $type, array $found, array $searches, int $limit): ToolResult
    {
        $failed = array_filter($found['lists'], static fn (array $list): bool => $list['items']->failed !== '');
        if (count($failed) === count($found['lists'])) {
            $notes = $this->dependenceNotes($found);

            return ToolResult::error(implode("\n", [
                sprintf(
                    'The choices for "%s" could not be computed (%s). This is not an empty list%s',
                    $column,
                    implode(', ', array_unique(array_map(static fn (array $list): string => $list['items']->failed, $failed))),
                    $notes === [] ? '; leave the column out.' : '.',
                ),
                ...$notes,
            ]));
        }

        $subject = sprintf('"%s" in "children.%s" of content type "%s"', $column, $children, $type);
        $notes   = $this->dependenceNotes($found);
        if (count($searches) > self::MAX_SEARCHES) {
            array_unshift($notes, sprintf('Only the first %d of %d searches were made.', self::MAX_SEARCHES, count($searches)));
            $searches = array_slice($searches, 0, self::MAX_SEARCHES);
        }

        $asked = implode(' or ', array_map($this->quotedName(...), $searches));
        $total = array_sum(array_map(static fn (array $list): int => count($list['items']->names()), $found['lists']));
        if ($total === 0) {
            return ToolResult::text(implode("\n", [sprintf('No choices: %s offers none for this record.', $subject), ...$this->failureLines($found), ...$notes]));
        }

        $matching = array_map(fn (array $list): array => $this->matching($list['items']->names(), $searches), $found['lists']);
        $hits     = array_sum(array_map(count(...), $matching));
        if ($hits === 0) {
            return ToolResult::text(implode("\n", [
                sprintf('None of the %d choices for %s contains %s.', $total, $subject, $asked),
                ...$this->nearestLines($found, $searches),
                ...$this->failureLines($found),
                ...$notes,
            ]));
        }

        [$lines, $shown] = $this->nameLines($found, $matching, $limit);

        return ToolResult::text(implode("\n", [
            sprintf('Choices for %s%s:', $subject, $searches === [] ? '' : ' that contain ' . $asked),
            ...$lines,
            ...$this->failureLines($found),
            ...$this->nearestLines($found, $searches),
            $shown < $hits
                ? sprintf('The list is cut: %d of %d are shown. Use "search" to find a name, or raise "limit" (at most %d).', $shown, $hits, self::MAX_LIMIT)
                : sprintf('All %d are shown.', $hits),
            ...$notes,
        ]));
    }

    /**
     * The names of each list, cut at the limit and at the byte budget of the
     * whole answer, and how many that are.
     *
     * @param array{by: string, lists: list<array{name: string, items: ProcessedSelectItems}>, others: list<string>, failures: list<string>} $found
     * @param list<list<string>>                                                                                                             $matching the names to show, per list
     *
     * @return array{list<string>, int}
     */
    private function nameLines(array $found, array $matching, int $limit): array
    {
        $lines  = [];
        $shown  = 0;
        $bytes  = 0;
        $hidden = 0;
        foreach ($found['lists'] as $index => $list) {
            $count = count($matching[$index] ?? []);
            $label = $this->listLabel($found['by'], $list['name']);
            $room  = self::MAX_NAME_BYTES - $bytes - strlen($label);
            $names = [];
            foreach ($matching[$index] ?? [] as $name) {
                $quoted = $this->quotedName($name);
                $room -= strlen($quoted) + 2;
                if ($shown >= $limit || $room < 0) {
                    break;
                }

                $names[] = $quoted;
                ++$shown;
            }

            if ($names !== []) {
                $line    = $label . (count($names) < $count ? sprintf('(%d of %d shown) ', count($names), $count) : '') . implode(', ', $names);
                $lines[] = $line;
                $bytes += strlen($line);
            } elseif ($count > 0) {
                ++$hidden;
            }
        }

        if ($hidden > 0) {
            $lines[] = sprintf('(%d more lists hold names that are not shown)', $hidden);
        }

        return [$lines, $shown];
    }

    /**
     * Each search without a hit, with the nearest names of every list, as far
     * as the byte budget goes; nothing for a search that has one.
     *
     * @param array{by: string, lists: list<array{name: string, items: ProcessedSelectItems}>, others: list<string>, failures: list<string>} $found
     * @param list<string>                                                                                                                   $searches
     *
     * @return list<string>
     */
    private function nearestLines(array $found, array $searches): array
    {
        $lines = [];
        $bytes = 0;
        $asked = 0;
        foreach ($searches as $search) {
            if ($this->hasHit($found, $search)) {
                continue;
            }

            $lines[] = sprintf('No name contains %s. Nearest names:', $this->quotedName($search));
            foreach ($found['lists'] as $list) {
                if ($list['items']->names() === []) {
                    continue;
                }

                // Asked before the list is sorted, which is what costs.
                $line = null;
                if ($bytes <= self::MAX_NEAR_BYTES && $asked < self::MAX_NEAR_LISTS) {
                    ++$asked;
                    $line = $this->listLabel($found['by'], $list['name'])
                        . implode(', ', array_map($this->quotedName(...), $list['items']->namesNear($search, self::MAX_NEAR_NAMES)));
                    $bytes += strlen($line);
                }

                if ($line === null || $bytes > self::MAX_NEAR_BYTES) {
                    $lines[] = '(further lists are left out; pass "set" or search for less)';

                    break;
                }

                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param array{by: string, lists: list<array{name: string, items: ProcessedSelectItems}>, others: list<string>, failures: list<string>} $found
     */
    private function hasHit(array $found, string $search): bool
    {
        foreach ($found['lists'] as $list) {
            if ($this->matching($list['items']->names(), [$search]) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The searches of the call: its comma-separated parts without empty and
     * repeated ones, each bounded in length.
     *
     * @return list<string>
     */
    private function searchesOf(string $given): array
    {
        $searches = [];
        foreach (explode(',', $given) as $part) {
            $search = trim(mb_substr(trim($part), 0, self::MAX_SEARCH_LENGTH));
            if ($search !== '' && !in_array($search, $searches, true)) {
                $searches[] = $search;
            }
        }

        return $searches;
    }

    /**
     * @param array{by: string, lists: list<array{name: string, items: ProcessedSelectItems}>, others: list<string>, failures: list<string>} $found
     *
     * @return list<string>
     */
    private function failureLines(array $found): array
    {
        $lines = [];
        $bytes = 0;
        foreach ([...$this->failuresOf($found['by'], $found['lists']), ...$found['failures']] as $line) {
            $bytes += strlen($line);
            if ($bytes > self::MAX_FAILURE_BYTES) {
                $lines[] = '(further lists that could not be computed are left out)';

                break;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param list<array{name: string, items: ProcessedSelectItems}> $lists
     *
     * @return list<string>
     */
    private function failuresOf(string $by, array $lists): array
    {
        $lines = [];
        foreach ($lists as $list) {
            if ($list['items']->failed !== '') {
                $lines[] = $this->listLabel($by, $list['name']) . sprintf('could not be computed (%s).', $list['items']->failed);
            }
        }

        return $lines;
    }

    /**
     * @param array{by: string, lists: list<array{name: string, items: ProcessedSelectItems}>, others: list<string>, failures: list<string>} $found
     *
     * @return list<string>
     */
    private function dependenceNotes(array $found): array
    {
        $notes = [];
        if ($found['by'] !== '') {
            $notes[] = sprintf('The choices depend on "%1$s": give the record that value too. Pass "set": {"%1$s": …} to list one of them.', $found['by']);
        }

        if ($found['others'] !== []) {
            $notes[] = sprintf('The choices can%s depend on %s; name it in "set".', $found['by'] === '' ? '' : ' also', implode(', ', $found['others']));
        }

        return $notes;
    }

    /**
     * The names that contain every word of one of the searches, all of them
     * without a search.
     *
     * @param list<string> $names
     * @param list<string> $searches
     *
     * @return list<string>
     */
    private function matching(array $names, array $searches): array
    {
        if ($searches === []) {
            return $names;
        }

        $wordLists = array_map(
            static fn (string $search): array => preg_split('/\s+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            $searches,
        );

        return array_values(array_filter($names, static function (string $name) use ($wordLists): bool {
            $name = mb_strtolower($name);
            foreach ($wordLists as $words) {
                if (array_filter($words, static fn (string $word): bool => !str_contains($name, $word)) === []) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function listLabel(string $by, string $name): string
    {
        return $by === '' ? '' : sprintf('%s %s: ', $by, $name === '' ? '(empty)' : $this->quotedName($name));
    }

    /**
     * A name as a JSON string literal, so one with a line break cannot start a
     * line of its own.
     */
    private function quotedName(string $name): string
    {
        return (string) json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * A name the model gave, echoed so that it can correct itself.
     */
    private function identifier(string $given): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', $given) ?? '', 0, 60);
    }
}
