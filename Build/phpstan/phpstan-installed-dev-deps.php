<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * PHPStan entry point of the ci:test:php:phpstan composer script in CI.
 *
 * Some matrix cells remove an optional dev dependency (remove-dev-deps in
 * .github/workflows/ci.yml): thieleundklose/autotranslate caps at
 * PHP <= 8.5.99. The files that reference an absent package's classes cannot
 * be analysed without it; they are excluded from analysis here and stay
 * covered by the cells that install the package. With every package
 * installed this file equals Build/phpstan/phpstan.neon.
 */

$root = dirname(__DIR__, 2);

$filesPerOptionalPackage = [
    'thieleundklose/autotranslate' => [
        'Classes/Integration/Autotranslate/AutotranslateCompilerPass.php',
        'Classes/Integration/Autotranslate/AutotranslateTranslator.php',
        'Classes/Integration/Autotranslate/LabelAutotranslatedRecord.php',
        'Classes/Integration/Autotranslate/MachineTranslatedText.php',
        'Classes/Integration/Autotranslate/MarkAutotranslatedRecord.php',
        'Tests/Functional/Integration/AutotranslateEndToEndTest.php',
        'Tests/Functional/Integration/AutotranslateIntegrationTest.php',
        'Tests/Unit/Integration/Autotranslate/AutotranslateCompilerPassTest.php',
        'Tests/Unit/Integration/Autotranslate/AutotranslateTranslatorTest.php',
        'Tests/Unit/Integration/Autotranslate/MarkAutotranslatedRecordTest.php',
    ],
];

$excludePaths = [];
foreach ($filesPerOptionalPackage as $package => $files) {
    if (is_dir($root . '/.Build/vendor/' . $package)) {
        continue;
    }
    foreach ($files as $file) {
        $excludePaths[] = $root . '/' . $file;
    }
}

return [
    'includes'   => [__DIR__ . '/phpstan.neon'],
    'parameters' => ['excludePaths' => ['analyseAndScan' => $excludePaths]],
];
