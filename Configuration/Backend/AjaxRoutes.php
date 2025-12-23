<?php

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;

return [
    'nrllm_test' => [
        'path' => '/nrllm/test',
        'target' => LlmModuleController::class . '::executeTestAction',
    ],
    'nrllm_config_toggle_active' => [
        'path' => '/nrllm/config/toggle-active',
        'target' => ConfigurationController::class . '::toggleActiveAction',
    ],
    'nrllm_config_set_default' => [
        'path' => '/nrllm/config/set-default',
        'target' => ConfigurationController::class . '::setDefaultAction',
    ],
    'nrllm_config_get_models' => [
        'path' => '/nrllm/config/get-models',
        'target' => ConfigurationController::class . '::getModelsAction',
    ],
    'nrllm_config_test' => [
        'path' => '/nrllm/config/test',
        'target' => ConfigurationController::class . '::testConfigurationAction',
    ],
];
