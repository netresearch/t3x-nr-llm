<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Rector Configuration - TYPO3 LLM Extension.
 *
 * The rule baseline (PHP/code-quality sets, common skips, importNames,
 * phpVersion and the Rector-specific PHPStan config) comes from the shared
 * org config in netresearch/typo3-ci-workflows. Only extension-specific
 * additions live here.
 */

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Ssch\TYPO3Rector\CodeQuality\General\AddErrorCodeToExceptionRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

$configure = require_once __DIR__ . '/../../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: code-quality sets, rule skips, phpstan-rector.neon
    $configure($rectorConfig, __DIR__ . '/../..');

    // paths() replaces the shared list — re-declared to keep Tests/ in scope,
    // which the shared $projectRoot default leaves out.
    $rectorConfig->paths(array_merge(
        [
            __DIR__ . '/../../Classes',
            __DIR__ . '/../../Configuration',
            __DIR__ . '/../../Resources',
            __DIR__ . '/../../Tests',
        ],
        glob(__DIR__ . '/../../ext_*.php') ?: [],
    ));

    $rectorConfig->sets([
        // PHPUnit sets - modernize tests
        PHPUnitSetList::PHPUNIT_110,

        // TYPO3 Sets - CRITICAL for TYPO3 migrations
        Typo3SetList::CODE_QUALITY,
        Typo3SetList::GENERAL,

        // TYPO3 v13 migration (v14 set adds ComponentFactory/cache imports not yet needed)
        Typo3LevelSetList::UP_TO_TYPO3_13,
    ]);

    // Not part of the shared TYPE_DECLARATION set
    $rectorConfig->rules([
        AddVoidReturnTypeWhereNoReturnRector::class,
    ]);

    $rectorConfig->skip([
        // Skip readonly for test properties that are set in setUp() - causes PHPStan errors
        ReadOnlyPropertyRector::class => [
            __DIR__ . '/../../Tests/Integration/Provider/OpenAiProviderIntegrationTest.php',
        ],
        // AddErrorCodeToExceptionRector assumes the standard \Throwable
        // ($message, $code, $previous) signature. Custom exceptions whose
        // constructor expects ($context, ?Throwable $previous) instead must
        // be skipped, otherwise Rector injects an int where a Throwable is
        // required.
        AddErrorCodeToExceptionRector::class => [
            __DIR__ . '/../../Classes/Provider/Middleware/BudgetMiddleware.php',
            __DIR__ . '/../../Classes/Service/Streaming/StreamingDispatcher.php',
            __DIR__ . '/../../Classes/Specialized/AbstractSpecializedService.php',
        ],
        // Skip Fuzzy tests - Eris\Generator namespace functions conflict with auto-imports
        __DIR__ . '/../../Tests/Fuzzy/',
    ]);
};
