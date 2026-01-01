<?php

declare(strict_types=1);

/**
 * Rector Configuration - TYPO3 LLM Extension
 * Based on TYPO3 Best Practices: https://github.com/TYPO3BestPractices/tea.
 *
 * This configuration enables:
 * - Automated TYPO3 v14 migrations
 * - PHP 8.5 modernization
 * - PHPUnit 12 test modernization
 * - Code quality improvements
 * - ExtEmConf automatic maintenance
 */

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Rector\ValueObject\PhpVersion;
use Ssch\TYPO3Rector\CodeQuality\General\ExtEmConfRector;
use Ssch\TYPO3Rector\Configuration\Typo3Option;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../Classes/',
        __DIR__ . '/../../Configuration/',
        __DIR__ . '/../../Tests/',
        __DIR__ . '/../../ext_emconf.php',
        __DIR__ . '/../../ext_localconf.php',
    ])
    // PHP 8.5 - TYPO3 v14 minimum
    ->withPhpVersion(PhpVersion::PHP_85)
    // Enable all PHP sets for modernization
    ->withPhpSets(true)
    ->withSets([
        // PHPUnit 12 Sets - modernize tests
        PHPUnitSetList::PHPUNIT_110,

        // TYPO3 Sets - CRITICAL for TYPO3 migrations
        Typo3SetList::CODE_QUALITY,
        Typo3SetList::GENERAL,

        // TYPO3 v14 migration
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ])
    // PHPStan integration for better analysis
    ->withPHPStanConfigs([
        Typo3Option::PHPSTAN_FOR_RECTOR_PATH,
    ])
    // Additional useful rules
    ->withRules([
        AddVoidReturnTypeWhereNoReturnRector::class,
    ])
    // ExtEmConfRector: Automatically maintains ext_emconf.php
    ->withConfiguredRule(ExtEmConfRector::class, [
        ExtEmConfRector::PHP_VERSION_CONSTRAINT => '8.5.0-8.99.99',
        ExtEmConfRector::TYPO3_VERSION_CONSTRAINT => '14.0.0-14.99.99',
        ExtEmConfRector::ADDITIONAL_VALUES_TO_BE_REMOVED => [],
    ])
    // Skip specific rules and files that cause issues
    ->withSkip([
        // Skip readonly for test properties that are set in setUp() - causes PHPStan errors
        ReadOnlyPropertyRector::class => [
            __DIR__ . '/../../Tests/Integration/Provider/OpenAiProviderIntegrationTest.php',
        ],
        // Skip Fuzzy tests - Eris\Generator namespace functions conflict with auto-imports
        __DIR__ . '/../../Tests/Fuzzy/',
    ])
    // Disable function imports for Fuzzy tests - Eris\Generator uses namespace functions
    ->withImportNames(true, true, false, false);
