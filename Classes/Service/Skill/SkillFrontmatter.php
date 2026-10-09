<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

/**
 * Reads the frontmatter fields a skill version is made of (ADR-214 item 1).
 *
 * The sync writes these fields from a freshly parsed ``SKILL.md`` and the
 * version-digest upgrade wizard re-derives them from the stored
 * ``raw_frontmatter``. Both must read the frontmatter the same way, or a row
 * the sync wrote would fail the wizard's comparison for no reason — so the
 * rules live here once.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class SkillFrontmatter
{
    /**
     * The ``allowed-tools`` declaration as the sync stores it.
     *
     * Returns null when the frontmatter carries no ``allowed-tools`` (or
     * ``allowed_tools``) key: the skill expresses no opinion. A string form
     * ("GetTca, GetEnv" / "GetTca GetEnv" / a single name) is split into a
     * list; only a genuinely empty value stays the declared-empty list, which
     * is a fail-closed "no tools".
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return list<mixed>|null
     */
    public static function allowedTools(array $frontmatter): ?array
    {
        if (!array_key_exists('allowed-tools', $frontmatter) && !array_key_exists('allowed_tools', $frontmatter)) {
            return null;
        }

        $tools = $frontmatter['allowed-tools'] ?? $frontmatter['allowed_tools'] ?? [];
        if (is_string($tools)) {
            $tools = preg_split('/[\s,]+/', trim($tools), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return is_array($tools) ? array_values($tools) : [];
    }

    /**
     * The process marker of ADR-214 item 6 (``process: true``).
     *
     * Only an explicit true value marks a process: a YAML boolean, or the
     * strings and numbers PHP's boolean filter reads as true. Anything else,
     * including an absent key, is not a process.
     *
     * @param array<string, mixed> $frontmatter
     */
    public static function isProcess(array $frontmatter): bool
    {
        $value = $frontmatter['process'] ?? null;
        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
