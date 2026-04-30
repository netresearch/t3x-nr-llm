<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Controller\Backend\SetupWizardController;
use Netresearch\NrLlm\Controller\Backend\TaskExecutionController;
use Netresearch\NrLlm\Controller\Backend\TaskListController;
use Netresearch\NrLlm\Controller\Backend\TaskWizardController;

/**
 * Backend module registration for nr_llm.
 *
 * Structure: Main module under 'tools', sub-modules as children of main module.
 * Sub-modules only appear in docheader dropdown, not in main navigation.
 *
 * Uses 'tools' as parent for v13+v14 compatibility:
 * - v13: 'tools' exists natively as the admin tools group
 * - v14: 'tools' is an alias for the new 'admin' group
 *
 * Pattern follows TYPO3 Styleguide extension:
 * - Main module identifier without prefix (e.g., 'nrllm' not 'tools_nrllm')
 * - Child modules with parent as prefix (e.g., 'nrllm_providers')
 * - Nested paths under main module path
 *
 * v13 compatibility: 'nrllm_overview' is registered as first submodule so that
 * v13 (which redirects to the first submodule) shows the overview page.
 * v14 uses 'showSubmoduleOverview' on the parent module for the same effect.
 */
return [
    // Main dashboard module (parent container)
    'nrllm' => [
        'parent' => 'tools',
        'position' => ['after' => 'styleguide'],
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm',
        'path' => '/module/nrllm',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'NrLlm',
        'appearance' => [
            'dependsOnSubmodules' => true,
        ],
        // v14+: Show overview page for parent module
        'showSubmoduleOverview' => true,
        'controllerActions' => [
            LlmModuleController::class => [
                'index',
                'test',
                'executeTest',
                'help',
            ],
        ],
    ],
    // Overview submodule - v13 compatibility
    // In v13, dependsOnSubmodules redirects to the first submodule.
    // This ensures the overview page is shown instead of providers.
    // In v14, showSubmoduleOverview on the parent handles this natively.
    'nrllm_overview' => [
        'parent' => 'nrllm',
        'position' => ['before' => '*'],
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm',
        'path' => '/module/nrllm/overview',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_overview.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            LlmModuleController::class => [
                'index',
                'test',
                'executeTest',
                'help',
            ],
        ],
    ],
    // Provider management - child of main module
    // Note: AJAX actions (toggleActive, testConnection) are registered via AjaxRoutes.php
    'nrllm_providers' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-provider',
        'path' => '/module/nrllm/providers',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_provider.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            ProviderController::class => [
                'list',
            ],
        ],
    ],
    // Model management - child of main module
    // Note: AJAX actions (toggleActive, setDefault, etc.) are registered via AjaxRoutes.php
    'nrllm_models' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-model',
        'path' => '/module/nrllm/models',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_model.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            ModelController::class => [
                'list',
            ],
        ],
    ],
    // Configuration management - child of main module
    // Note: AJAX actions (toggleActive, setDefault, testConfiguration) are registered via AjaxRoutes.php
    'nrllm_configurations' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm',
        'path' => '/module/nrllm/configurations',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_config.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            ConfigurationController::class => [
                'list',
                'wizardForm',
                'wizardGenerate',
            ],
        ],
    ],
    // Task management - child of main module
    // Note: new/edit/save/delete use FormEngine (record_edit route), AJAX actions via AjaxRoutes.php
    'nrllm_tasks' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-task',
        'path' => '/module/nrllm/tasks',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_task.xlf',
        'extensionName' => 'NrLlm',
        // Slice 13e split (ADR-027): list / execute / wizard each
        // own a focused controller. Module identifier stays
        // `nrllm_tasks` and the action names are unchanged so any
        // bookmarked URL or backend-history link keeps resolving.
        'controllerActions' => [
            TaskListController::class      => ['list'],
            TaskExecutionController::class => ['executeForm'],
            TaskWizardController::class    => [
                'wizardForm',
                'wizardGenerate',
                'wizardGenerateChain',
                'wizardCreate',
            ],
        ],
    ],
    // Setup wizard - child of main module
    // Note: AJAX actions (detect, test, discover, generate, save) are registered via AjaxRoutes.php
    'nrllm_wizard' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-wizard',
        'path' => '/module/nrllm/wizard',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_wizard.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            SetupWizardController::class => [
                'index',
            ],
        ],
    ],
];
