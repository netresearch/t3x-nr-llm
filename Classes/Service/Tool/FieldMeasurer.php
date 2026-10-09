<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\FieldMeasure;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The length of a proposed value and the range configured for its field
 * (ADR-214, item 9), the one place a structured preview measures a value.
 *
 * Ranges are the operator's, read from the extension configuration under
 * `tools.structuredPreview.ranges`: comma-separated entries of the form
 * `table.field:min-max`. The shipped default is `pages.description:140-160`,
 * the meta description length of the product specification "Guided Tour: SEO
 * Optimierung", section 5. No other field has a default.
 *
 * - An absent key, or configuration that cannot be read, is the shipped
 *   default: an installation that never saved its settings gets the range it
 *   was shipped with.
 * - An empty value is "no ranges at all": the operator removed the default.
 * - An entry that cannot be read gives its field no range; the others stand.
 *   A range is a display aid, so an unreadable one costs the hint, not the card.
 */
final readonly class FieldMeasurer
{
    public const DEFAULT_RANGES = 'pages.description:140-160';

    private const ENTRY_PATTERN = '/\A([A-Za-z0-9_]{1,64})\.([A-Za-z0-9_]{1,64}):(\d{1,6})-(\d{1,6})\z/';

    public function __construct(
        // Null where no configuration can be asked, as in a tool built by
        // hand; that reads like an absent key, so the shipped default applies.
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    /**
     * The measure of `$value` for `table.field`, or null when there is none to
     * give: no range is configured and the caller says the field is not one
     * whose bare length means anything (a body text, a select).
     *
     * @param bool $countWithoutRange whether the count alone is worth showing: a single-line or meta field
     * @param bool $richText          whether the value is stored HTML, whose markup is not counted
     */
    public function measure(string $table, string $field, string $value, bool $countWithoutRange, bool $richText = false): ?FieldMeasure
    {
        $range = $this->rangeFor($table, $field);
        if ($range === null && !$countWithoutRange) {
            return null;
        }

        $count = mb_strlen($richText ? self::plainText($value) : $value);

        return $range === null
            ? new FieldMeasure($count)
            : new FieldMeasure($count, $range[0], $range[1]);
    }

    /**
     * The configured range of `table.field` as [min, max], or null.
     *
     * @return array{int, int}|null
     */
    public function rangeFor(string $table, string $field): ?array
    {
        return $this->ranges()[$table . '.' . $field] ?? null;
    }

    /**
     * The text a reader sees in a stored rich-text value: tags removed, entities
     * decoded. Only for counting; the value itself is never changed.
     */
    public static function plainText(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return array<string, array{int, int}>
     */
    private function ranges(): array
    {
        $configured = $this->configured();
        $ranges     = [];
        foreach (explode(',', $configured ?? self::DEFAULT_RANGES) as $entry) {
            if (preg_match(self::ENTRY_PATTERN, trim($entry), $match) !== 1) {
                continue;
            }

            $min = (int)$match[3];
            $max = (int)$match[4];
            if ($min > $max) {
                continue;
            }

            $ranges[$match[1] . '.' . $match[2]] = [$min, $max];
        }

        return $ranges;
    }

    /**
     * The configured string, or null for "use the shipped default": no
     * configuration to ask, configuration that cannot be read, an absent key,
     * or a value that is not a string.
     */
    private function configured(): ?string
    {
        if (!$this->extensionConfiguration instanceof ExtensionConfiguration) {
            return null;
        }

        try {
            $node = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return null;
        }

        foreach (['tools', 'structuredPreview', 'ranges'] as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }
}
