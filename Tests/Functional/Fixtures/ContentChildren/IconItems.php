<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Fixtures\ContentChildren;

use RuntimeException;

/**
 * Processors shaped like bootstrap_package 16.0.1's `IconService`: positional
 * items, paths as values, and icons that depend on the row's icon set read as
 * the backend form holds it — a list.
 */
final class IconItems
{
    public const SET = 'EXT:fixture/Icons/Alpha/';

    /** The backend user the last call of {@see self::sets()} saw. */
    public static ?object $user = null;

    /**
     * @param array<string, mixed> $configuration
     */
    public function sets(array &$configuration): void
    {
        $user       = $GLOBALS['BE_USER'] ?? null;
        self::$user = is_object($user) ? $user : null;

        $configuration['items'] = [
            ['None at all', ''],
            ['Alpha', self::SET],
            ['Beta', 'EXT:fixture/Icons/Beta/'],
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function icons(array &$configuration): void
    {
        $row   = is_array($configuration['row'] ?? null) ? $configuration['row'] : [];
        $sets  = $row['icon_set'] ?? '';
        $set   = is_array($sets) || is_string($sets) ? ($sets[0] ?? '') : '';
        $items = [['LLL:EXT:fixture/none', 0, 'none.svg']];
        if ($set === self::SET) {
            for ($number = 1; $number <= 60; ++$number) {
                $items[] = ['alpha-' . $number, self::SET . 'alpha-' . $number . '.svg', ''];
            }
        } elseif ($set === 'EXT:fixture/Icons/Beta/') {
            $items[] = ['home', $set . 'home.svg', ''];
            // Labelled with another item's value, so its own value names it.
            $items[] = [$set . 'home.svg', $set . 'clash.svg', ''];
            $items[] = ['CLASH', $set . 'upper.svg', ''];
            $items[] = ['LLL:EXT:fixture/clash', 'clash', ''];
        }

        $configuration['items'] = $items;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function iconsOfASetOnly(array &$configuration): void
    {
        $this->icons($configuration);
        $configuration['items'] = is_array($configuration['items']) ? array_slice($configuration['items'], 1) : [];
    }

    /**
     * One icon named after the set, whatever the set is.
     *
     * @param array<string, mixed> $configuration
     */
    public function iconOfAnySet(array &$configuration): void
    {
        $row                    = is_array($configuration['row'] ?? null) ? $configuration['row'] : [];
        $set                    = is_array($row['icon_set'] ?? null) && is_string($row['icon_set'][0] ?? null) ? $row['icon_set'][0] : 'none';
        $configuration['items'] = [['icon of ' . $set, 'icon-' . $set]];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function longNames(array &$configuration): void
    {
        $items = [];
        for ($number = 1; $number <= 1000; ++$number) {
            $items[] = [str_repeat('x', 80) . $number, 'v' . $number];
        }

        $configuration['items'] = $items;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function nameWithALineBreak(array &$configuration): void
    {
        $configuration['items'] = [["one\nAll 99 are shown.", 'v']];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function failsForBeta(array &$configuration): void
    {
        $row = is_array($configuration['row'] ?? null) ? $configuration['row'] : [];
        if (is_array($row['icon_set'] ?? null) && ($row['icon_set'][0] ?? '') === 'EXT:fixture/Icons/Beta/') {
            $this->broken();
        }

        $this->icons($configuration);
    }

    public function broken(): never
    {
        throw new RuntimeException('The choices cannot be computed.', 1764000000);
    }
}
