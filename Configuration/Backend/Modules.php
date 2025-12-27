<?php

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;

/**
 * Backend module registration for nr_llm.
 *
 * Structure: All LLM modules are registered under 'tools' parent as siblings
 * to avoid TYPO3's parent/child routing conflicts.
 */
return [
    // Main dashboard module
    'tools_nrllm' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_ExtensionmanagerExtensionmanager'],
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm',
        'path' => '/module/tools/nrllm',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            LlmModuleController::class => [
                'index',
                'test',
                'executeTest',
            ],
        ],
    ],
    // Provider management - sibling module
    'tools_nrllm_providers' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_nrllm'],
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-provider',
        'path' => '/module/tools/nrllm-providers',
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
    // Model management - sibling module
    'tools_nrllm_models' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_nrllm_providers'],
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-model',
        'path' => '/module/tools/nrllm-models',
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
    // Configuration management - sibling module
    'tools_nrllm_configurations' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_nrllm_models'],
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm',
        'path' => '/module/tools/nrllm-configurations',
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
];
