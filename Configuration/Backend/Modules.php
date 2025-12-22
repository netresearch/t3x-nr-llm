<?php

declare(strict_types=1);

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
];
