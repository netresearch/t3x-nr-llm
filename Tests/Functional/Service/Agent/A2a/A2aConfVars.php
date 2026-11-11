<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

/**
 * Sets or removes one value below `$GLOBALS['TYPO3_CONF_VARS']`, the way the
 * other tests of this suite do: a copy of the whole array, written back.
 */
final class A2aConfVars
{
    /**
     * @param non-empty-list<string> $path
     */
    public static function set(array $path, mixed $value): void
    {
        $conf                       = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $GLOBALS['TYPO3_CONF_VARS'] = self::with(is_array($conf) ? $conf : [], $path, $value, false);
    }

    /**
     * @param non-empty-list<string> $path
     */
    public static function remove(array $path): void
    {
        $conf                       = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $GLOBALS['TYPO3_CONF_VARS'] = self::with(is_array($conf) ? $conf : [], $path, null, true);
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $path
     *
     * @return array<mixed>
     */
    private static function with(array $node, array $path, mixed $value, bool $remove): array
    {
        $key = array_shift($path);
        if ($key === null) {
            return $node;
        }

        if ($path === []) {
            if ($remove) {
                unset($node[$key]);
            } else {
                $node[$key] = $value;
            }

            return $node;
        }

        $child      = $node[$key] ?? [];
        $node[$key] = self::with(is_array($child) ? $child : [], $path, $value, $remove);

        return $node;
    }
}
