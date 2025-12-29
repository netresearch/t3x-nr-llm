<?php

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Controller\Backend\SetupWizardController;

return [
    // Main module
    'nrllm_test' => [
        'path' => '/nrllm/test',
        'target' => LlmModuleController::class . '::executeTestAction',
    ],

    // Provider routes
    'nrllm_provider_toggle_active' => [
        'path' => '/nrllm/provider/toggle-active',
        'target' => ProviderController::class . '::toggleActiveAction',
    ],
    'nrllm_provider_test_connection' => [
        'path' => '/nrllm/provider/test-connection',
        'target' => ProviderController::class . '::testConnectionAction',
    ],

    // Model routes
    'nrllm_model_toggle_active' => [
        'path' => '/nrllm/model/toggle-active',
        'target' => ModelController::class . '::toggleActiveAction',
    ],
    'nrllm_model_set_default' => [
        'path' => '/nrllm/model/set-default',
        'target' => ModelController::class . '::setDefaultAction',
    ],
    'nrllm_model_get_by_provider' => [
        'path' => '/nrllm/model/get-by-provider',
        'target' => ModelController::class . '::getByProviderAction',
    ],
    'nrllm_model_test' => [
        'path' => '/nrllm/model/test',
        'target' => ModelController::class . '::testModelAction',
    ],

    // Configuration routes
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

    // Setup Wizard routes
    'nrllm_wizard_detect' => [
        'path' => '/nrllm/wizard/detect',
        'target' => SetupWizardController::class . '::detectAction',
    ],
    'nrllm_wizard_test' => [
        'path' => '/nrllm/wizard/test',
        'target' => SetupWizardController::class . '::testAction',
    ],
    'nrllm_wizard_discover' => [
        'path' => '/nrllm/wizard/discover',
        'target' => SetupWizardController::class . '::discoverAction',
    ],
    'nrllm_wizard_generate' => [
        'path' => '/nrllm/wizard/generate',
        'target' => SetupWizardController::class . '::generateAction',
    ],
    'nrllm_wizard_save' => [
        'path' => '/nrllm/wizard/save',
        'target' => SetupWizardController::class . '::saveAction',
    ],
];
