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
use Netresearch\NrLlm\Controller\Backend\TaskController;

/**
 * Backend module registration for nr_llm.
 *
 * Structure: Main module under 'admin', sub-modules as children of main module.
 * Sub-modules only appear in docheader dropdown, not in main navigation.
 *
 * Pattern follows TYPO3 Styleguide extension:
 * - Main module identifier without prefix (e.g., 'nrllm' not 'tools_nrllm')
 * - Child modules with parent as prefix (e.g., 'nrllm_providers')
 * - Nested paths under main module path
 */
return [
    // Main dashboard module (parent container)
    'nrllm' => [
        'parent' => 'admin',
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
        'controllerActions' => [
            TaskController::class => [
                'list',
                'executeForm',
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
