<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgSpriteIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// An icon drawn in currentColor must reach the page as markup that inherits
// the surrounding text colour. SvgIconProvider renders `<img src="…svg">`, and
// an image document resolves currentColor against its own initial colour —
// black — so in the dark backend scheme those icons painted near-black on a
// dark surface. SvgSpriteIconProvider renders `<svg><use href="file.svg#id">`,
// the markup core uses for its own icons, which inherits `color`. `source`
// keeps the inline variant (module menu) reading the same file, and the
// fragment is the id on that file's root <svg>.
$iconPath = 'EXT:nr_llm/Resources/Public/Icons/';
$currentColorIcon = static fn(string $file): array => [
    'provider' => SvgSpriteIconProvider::class,
    'sprite' => $iconPath . $file . '.svg#nrllm-' . strtolower($file),
    'source' => $iconPath . $file . '.svg',
];

// TYPO3 v14 ships a redesigned backend: use the flat, three-color icons that
// adapt via currentColor. v13 uses the full-bleed teal-tile variants that match
// the classic module menu; those carry fixed fills, so `<img>` renders them
// correctly in either scheme.
$moduleIcon = (new Typo3Version())->getMajorVersion() >= 14
    ? $currentColorIcon
    : static fn(string $file): array => [
        'provider' => SvgIconProvider::class,
        'source' => $iconPath . $file . '.legacy.svg',
    ];

return [
    // Module icons
    'module-nrllm' => $moduleIcon('module-nrllm'),
    'module-nrllm-provider' => $moduleIcon('Provider'),
    'module-nrllm-model' => $moduleIcon('Model'),
    'module-nrllm-wizard' => $moduleIcon('Wizard'),
    'module-nrllm-task' => $moduleIcon('Task'),
    'module-nrllm-snippet' => $moduleIcon('Snippet'),
    'module-nrllm-analytics' => $moduleIcon('Analytics'),
    'module-nrllm-runs' => $moduleIcon('Runs'),
    'module-nrllm-skill' => $moduleIcon('Skill'),
    'module-nrllm-tool' => $moduleIcon('module-nrllm-tool'),

    // Record icons (TCA ctrl.typeicon_classes). Registered here rather than
    // through ctrl.iconfile, which core registers with SvgIconProvider.
    'nrllm-record-model' => $currentColorIcon('Model'),
    'nrllm-record-mcp-server' => $currentColorIcon('module-nrllm-tool'),
    'nrllm-record-provider' => $currentColorIcon('Provider'),
    'nrllm-record-skill' => $currentColorIcon('Skill'),
    'nrllm-record-snippet' => $currentColorIcon('Snippet'),
    'nrllm-record-task' => $currentColorIcon('Task'),

    // Provider type icons
    'nrllm-provider-openai' => $currentColorIcon('provider-openai'),
    'nrllm-provider-claude' => $currentColorIcon('provider-claude'),
    'nrllm-provider-gemini' => $currentColorIcon('provider-gemini'),
    'nrllm-provider-openrouter' => $currentColorIcon('provider-openrouter'),
    'nrllm-provider-mistral' => $currentColorIcon('provider-mistral'),
    'nrllm-provider-groq' => $currentColorIcon('provider-groq'),

    // Editor actions (ADR-152) — one per writing tool, rendered by the Tools
    // module beside the action's translated name. No `.legacy` twin: these are
    // inline list icons, not module tiles, so the v14 three-color style reads
    // correctly on v13 as well.
    'nrllm-editor-action-page-metadata' => $currentColorIcon('editor-action-page-metadata'),
    'nrllm-editor-action-file-alt-text' => $currentColorIcon('editor-action-file-alt-text'),
    'nrllm-editor-action-file-meta' => $currentColorIcon('editor-action-file-meta'),
    'nrllm-editor-action-attach-file' => $currentColorIcon('editor-action-attach-file'),
    'nrllm-editor-action-page-social-image' => $currentColorIcon('editor-action-page-social-image'),
    'nrllm-editor-action-move-content' => $currentColorIcon('editor-action-move-content'),
    'nrllm-editor-action-create-content' => $currentColorIcon('editor-action-create-content'),
    'nrllm-editor-action-create-page' => $currentColorIcon('editor-action-create-page'),
    'nrllm-editor-action-create-translation' => $currentColorIcon('editor-action-create-translation'),
];
