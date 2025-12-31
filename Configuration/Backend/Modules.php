<?php

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
                'edit',
                'create',
                'update',
                'delete',
                'toggleActive',
                'testConnection',
            ],
        ],
    ],
    // Model management - child of main module
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
                'edit',
                'create',
                'update',
                'delete',
                'toggleActive',
                'setDefault',
                'getByProvider',
            ],
        ],
    ],
    // Configuration management - child of main module
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
                'edit',
                'create',
                'update',
                'delete',
            ],
        ],
    ],
    // Task management - child of main module
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
                'new',
                'edit',
                'save',
                'delete',
                'executeForm',
            ],
        ],
    ],
    // Setup wizard - child of main module
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
                'detect',
                'test',
                'discover',
                'generate',
                'save',
            ],
        ],
    ],
];
