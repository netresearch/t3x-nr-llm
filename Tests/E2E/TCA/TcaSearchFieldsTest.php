<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\TCA;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TYPO3 v14 removed `ctrl.searchFields` (#106972); its TCA migration strips
 * the option and logs a deprecation for every table that still sets it. The
 * base TCA files therefore must not set it. TYPO3 v13 gets it from
 * Configuration/TCA/Overrides/v13_search_fields.php, which only applies there.
 */
final class TcaSearchFieldsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function tcaFileProvider(): array
    {
        $files = glob(__DIR__ . '/../../../Configuration/TCA/*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file, '.php')] = [$file];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('tcaFileProvider')]
    public function baseTcaDoesNotSetSearchFields(string $tcaFile): void
    {
        $tca = require $tcaFile;

        self::assertIsArray($tca);
        self::assertIsArray($tca['ctrl'] ?? null);
        self::assertArrayNotHasKey('searchFields', $tca['ctrl']);
    }
}
