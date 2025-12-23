<?php

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;

return [
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
    'tools_nrllm_configurations' => [
        'parent' => 'tools_nrllm',
        'position' => ['after' => 'tools_nrllm'],
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm',
        'path' => '/module/tools/nrllm/configurations',
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
