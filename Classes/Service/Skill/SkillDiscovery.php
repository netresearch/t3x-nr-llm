<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

final class SkillDiscovery
{
    private const PATTERNS = [
        '#^SKILL\.md$#',
        '#^skills/[^/]+/SKILL\.md$#',
        '#^\.claude/skills/[^/]+/SKILL\.md$#',
        '#^[^/]+/skills/[^/]+/SKILL\.md$#',
    ];

    /**
     * @param list<string> $treePaths
     *
     * @return list<string>
     */
    public function discover(array $treePaths): array
    {
        $matched = [];
        foreach (self::PATTERNS as $pattern) {
            foreach ($treePaths as $path) {
                if (preg_match($pattern, $path) === 1 && !in_array($path, $matched, true)) {
                    $matched[] = $path;
                }
            }
        }
        return $matched;
    }
}
