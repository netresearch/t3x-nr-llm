<?php

declare(strict_types=1);

/**
 * Fractor Configuration - TYPO3 LLM Extension
 *
 * Fractor handles configuration file migrations:
 * - Fluid templates
 * - TypoScript
 * - YAML files
 * - .htaccess files
 * - XML files (including XLIFF)
 */

use a9f\Fractor\Configuration\FractorConfiguration;
use a9f\Typo3Fractor\Set\Typo3LevelSetList;

return FractorConfiguration::configure()
    ->withPaths([
        __DIR__ . '/../../Configuration/',
        __DIR__ . '/../../Resources/',
    ])
    ->withSets([
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ])
    ->withSkip([
        // Skip vendor and build directories
        __DIR__ . '/../../.Build/',
    ]);
