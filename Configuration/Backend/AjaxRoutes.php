<?php

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\TaskController;
use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Controller\Backend\SetupWizardController;

/**
 * AJAX routes for nr_llm backend module.
 *
 * Note: TYPO3 automatically prefixes these route names with 'ajax_'
 * when registering them, so 'nrllm_provider_toggle_active' becomes
 * accessible as 'ajax_nrllm_provider_toggle_active'.
 */
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
    'nrllm_model_fetch_available' => [
        'path' => '/nrllm/model/fetch-available',
        'target' => ModelController::class . '::fetchAvailableModelsAction',
    ],
    'nrllm_model_detect_limits' => [
        'path' => '/nrllm/model/detect-limits',
        'target' => ModelController::class . '::detectLimitsAction',
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

    // Task routes
    'nrllm_task_list_tables' => [
        'path' => '/nrllm/task/list-tables',
        'target' => TaskController::class . '::listTablesAction',
    ],
    'nrllm_task_fetch_records' => [
        'path' => '/nrllm/task/fetch-records',
        'target' => TaskController::class . '::fetchRecordsAction',
    ],
    'nrllm_task_load_record_data' => [
        'path' => '/nrllm/task/load-record-data',
        'target' => TaskController::class . '::loadRecordDataAction',
    ],
    'nrllm_task_refresh_input' => [
        'path' => '/nrllm/task/refresh-input',
        'target' => TaskController::class . '::refreshInputAction',
    ],
    'nrllm_task_execute' => [
        'path' => '/nrllm/task/execute',
        'target' => TaskController::class . '::executeAction',
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
