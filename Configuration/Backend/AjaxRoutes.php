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
use Netresearch\NrLlm\Controller\Backend\TaskRecordsController;

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
    'nrllm_config_model_constraints' => [
        'path' => '/nrllm/config/model-constraints',
        'target' => ConfigurationController::class . '::getModelConstraintsAction',
    ],

    // Task routes — slice 13e split (ADR-027): record-picker AJAX
    // moves to TaskRecordsController, execute / refresh-input AJAX
    // moves to TaskExecutionController. Route identifiers stay so
    // the JS frontend (resolved via PageRenderer::addInlineSettingArray)
    // is unaffected.
    'nrllm_task_list_tables' => [
        'path' => '/nrllm/task/list-tables',
        'target' => TaskRecordsController::class . '::listTablesAction',
    ],
    'nrllm_task_fetch_records' => [
        'path' => '/nrllm/task/fetch-records',
        'target' => TaskRecordsController::class . '::fetchRecordsAction',
    ],
    'nrllm_task_load_record_data' => [
        'path' => '/nrllm/task/load-record-data',
        'target' => TaskRecordsController::class . '::loadRecordDataAction',
    ],
    'nrllm_task_refresh_input' => [
        'path' => '/nrllm/task/refresh-input',
        'target' => TaskExecutionController::class . '::refreshInputAction',
    ],
    'nrllm_task_execute' => [
        'path' => '/nrllm/task/execute',
        'target' => TaskExecutionController::class . '::executeAction',
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
