<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\FieldFill;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Which fields an installation opted in to filling on save (ADR-237).
 *
 * Nothing is opted in by default. A field counts only when its TCA makes it
 * plain text: a slug, a relation, a rich text field or a code editor is a
 * different risk, and listing one must not reach it. Accounts and system records are out whatever
 * the list says, because their text is not content.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class FieldFillSettings
{
    public const DEFAULT_MAX_LENGTH = 300;

    /** Tables whose records are configuration or people, not content. */
    private const REFUSED_TABLES = ['be_users', 'be_groups', 'fe_users', 'fe_groups', 'sys_template'];

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @return list<string> the opted-in fields of a table
     */
    public function fieldsOf(string $table): array
    {
        $fields = [];
        foreach ($this->entries() as $entry) {
            [$entryTable, $field] = array_pad(explode('.', $entry, 2), 2, '');
            if ($entryTable === $table && $this->isPlainText($table, $field)) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    public function isOptedIn(string $table, string $field): bool
    {
        return in_array($field, $this->fieldsOf($table), true);
    }

    /**
     * The longest value written: the setting, or less where the TCA says so.
     */
    public function maxLength(string $table, string $field): int
    {
        $configured = $this->settings()['maxLength'] ?? null;
        $length     = is_numeric($configured) && (int) $configured > 0 ? (int) $configured : self::DEFAULT_MAX_LENGTH;

        $max = $this->tcaConfig($table, $field)['max'] ?? null;

        return is_numeric($max) && (int) $max > 0 ? min($length, (int) $max) : $length;
    }

    private function isPlainText(string $table, string $field): bool
    {
        if (in_array($table, self::REFUSED_TABLES, true) || str_starts_with($table, 'sys_')) {
            return false;
        }

        $config = $this->tcaConfig($table, $field);

        return in_array($config['type'] ?? null, ['input', 'text'], true)
            && empty($config['enableRichtext'])
            && !isset($config['renderType'])
            && !$this->isRichTextInAType($table, $field);
    }

    /**
     * Core switches the rich text editor on per record type, as it does for the
     * body text of a content element.
     */
    private function isRichTextInAType(string $table, string $field): bool
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $types = $GLOBALS['TCA'][$table]['types'] ?? [];

        foreach (is_array($types) ? $types : [] as $type) {
            // @phpstan-ignore-next-line TCA access returns mixed at each nesting level
            $overrides = is_array($type) ? ($type['columnsOverrides'][$field]['config'] ?? null) : null;
            if (is_array($overrides) && !empty($overrides['enableRichtext'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function tcaConfig(string $table, string $field): array
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;

        return is_array($config) ? $config : [];
    }

    /**
     * @return list<string>
     */
    private function entries(): array
    {
        $fields = $this->settings()['fields'] ?? '';
        if (!is_string($fields)) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $fields)), static fn (string $entry): bool => $entry !== ''));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function settings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get('nr_llm', 'fieldFill');
        } catch (Throwable) {
            return [];
        }

        return is_array($settings) ? $settings : [];
    }
}
