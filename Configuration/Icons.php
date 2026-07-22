<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14 ships a redesigned backend with light/dark mode: use the flat,
// three-color icons that adapt via currentColor. v13 uses the full-bleed
// teal-tile variants that match the classic module menu.
$legacySuffix = (new Typo3Version())->getMajorVersion() >= 14 ? '' : '.legacy';

return [
    // Module icons
    'module-nrllm' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/module-nrllm' . $legacySuffix . '.svg',
    ],
    'module-nrllm-provider' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Provider' . $legacySuffix . '.svg',
    ],
    'module-nrllm-model' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Model' . $legacySuffix . '.svg',
    ],
    'module-nrllm-wizard' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Wizard' . $legacySuffix . '.svg',
    ],
    'module-nrllm-task' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Task' . $legacySuffix . '.svg',
    ],
    'module-nrllm-snippet' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Snippet' . $legacySuffix . '.svg',
    ],
    'module-nrllm-analytics' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Analytics' . $legacySuffix . '.svg',
    ],
    'module-nrllm-runs' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Runs' . $legacySuffix . '.svg',
    ],
    'module-nrllm-skill' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/Skill' . $legacySuffix . '.svg',
    ],
    'module-nrllm-tool' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/module-nrllm-tool' . $legacySuffix . '.svg',
    ],

    // Provider type icons
    'nrllm-provider-openai' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-openai.svg',
    ],
    'nrllm-provider-claude' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-claude.svg',
    ],
    'nrllm-provider-gemini' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-gemini.svg',
    ],
    'nrllm-provider-openrouter' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-openrouter.svg',
    ],
    'nrllm-provider-mistral' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-mistral.svg',
    ],
    'nrllm-provider-groq' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/provider-groq.svg',
    ],
];
