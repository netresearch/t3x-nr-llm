<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Controller\Backend\AgentRunController;
use Netresearch\NrLlm\Controller\Backend\AiTaskController;
use Netresearch\NrLlm\Controller\Backend\AnalyticsController;
use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\EditorActionController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\McpServerController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\PromptSnippetController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Controller\Backend\SetupWizardController;
use Netresearch\NrLlm\Controller\Backend\SkillSourceController;
use Netresearch\NrLlm\Controller\Backend\TaskExecutionController;
use Netresearch\NrLlm\Controller\Backend\TaskListController;
use Netresearch\NrLlm\Controller\Backend\TaskWizardController;
use Netresearch\NrLlm\Controller\Backend\ToolController;
use Netresearch\NrLlm\Controller\Backend\ToolPlaygroundController;

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
                'governance',
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
        // `governance` is the read-only effective-policy readout (ADR-140). It
        // is an action of this module rather than a fourteenth flat submodule:
        // ADR-119 already calls twelve entries a dumping ground, and the
        // readout is a property of the overview, not a management surface.
        'controllerActions' => [
            LlmModuleController::class => [
                'index',
                'test',
                'executeTest',
                'governance',
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
    // Prompt snippet library - child of main module
    // Note: new/edit/save/delete use FormEngine (record_edit route)
    'nrllm_snippets' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-snippet',
        'path' => '/module/nrllm/snippets',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_snippet.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            PromptSnippetController::class => [
                'list',
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
    // Skills management - child of main module
    // Note: AJAX actions (sync, toggleSkill, setToken) are registered via AjaxRoutes.php
    'nrllm_skills' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-skill',
        'path' => '/module/nrllm/skills',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_skill.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            SkillSourceController::class => [
                'list',
            ],
        ],
    ],
    // Tool management - child of main module
    // Admin-only: list every registered tool and toggle its global enable state.
    // Note: the AJAX toggleToolAction is registered via AjaxRoutes.php
    // (nrllm_tool_toggle) and additionally guards itself with
    // RequiresBackendAdminTrait (ADR-037).
    'nrllm_tools' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-tool',
        'path' => '/module/nrllm/tools',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_tool.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            ToolController::class => [
                'list',
            ],
        ],
    ],
    // MCP servers - child of main module
    // Admin-only: list the operator-configured MCP servers, what each one
    // advertised at the last import, and trigger a fresh import. The import
    // talks to a third party over the network, so it happens only on this
    // explicit action; the AJAX route (nrllm_mcp_import) guards itself with
    // RequiresBackendAdminTrait because a backend route bypasses this
    // module's access setting (ADR-037).
    'nrllm_mcp' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-tool',
        'path' => '/module/nrllm/mcp',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_mcp.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            McpServerController::class => [
                'list',
            ],
        ],
    ],
    // Tool playground - child of main module
    // Admin-only interactive playground for the tool runtime (ADR-037/038):
    // pick a configuration, enter a prompt and run the bounded agent loop.
    // Note: the AJAX runAction is registered via AjaxRoutes.php (nrllm_tool_run)
    // and additionally guards itself with RequiresBackendAdminTrait.
    'nrllm_playground' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-tool',
        'path' => '/module/nrllm/playground',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_playground.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            ToolPlaygroundController::class => [
                'list',
            ],
        ],
    ],
    // Agent Runs approvals inbox - child of main module (ADR-109)
    // Admin-only: surfaces runs suspended WAITING_FOR_APPROVAL (ADR-084) or
    // WAITING_FOR_INPUT (ADR-105) and lets an admin approve/deny or submit the
    // required typed input. Native <form> PRG, no AjaxRoutes; access => admin
    // gates the three list/write actions, and `show` is additionally authorised
    // per run by the runtime (AGENT_READ).
    'nrllm_runs' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-runs',
        'path' => '/module/nrllm/runs',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_runs.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            AgentRunController::class => [
                'list',
                'approve',
                'submitInput',
                // Read-only run detail (ADR-153); authorised per run by the
                // runtime with AGENT_READ, which — unlike the approval the two
                // write actions above ask for — has no grant equivalent.
                'show',
            ],
        ],
    ],
    // Usage analytics dashboard - child of main module
    'nrllm_analytics' => [
        'parent' => 'nrllm',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-analytics',
        'path' => '/module/nrllm/analytics',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_analytics.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            AnalyticsController::class => [
                'index',
            ],
        ],
    ],
    // Editor-facing task/approvals module (ADR-131). Deliberately OUTSIDE the
    // admin-only 'nrllm' tree: the menu filters out every top-level module
    // whose own access check fails, so a child of 'nrllm' would be invisible
    // to non-admins. Lives in the 'web' group where editors work.
    //
    // access => 'user' (NOT 'user,group': v14 resolves access strings through
    // a gate registry that only knows user/admin/systemMaintainer — anything
    // else denies EVERYONE, admins included). 'user' means the module must be
    // ticked in be_groups; the tasks_use/agent_approve grants are checked per
    // action on top — the module switch alone never grants execution.
    'nrllm_aitasks' => [
        'parent' => 'web',
        'access' => 'user',
        'iconIdentifier' => 'module-nrllm-task',
        'path' => '/module/web/nrllm-aitasks',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_aitasks.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            AiTaskController::class => [
                'list',
                'executeForm',
            ],
            // The Editor Action Center (ADR-158). Here rather than in a module
            // of its own: it is the same audience, the same grant and the same
            // inbox as the two entries below, and ADR-119 already calls the
            // admin tree's twelve entries a dumping ground. Both actions
            // re-check the `tasks_use` grant — the module switch alone never
            // grants execution — and which actions exist is the tool gate's
            // answer, not this registration's.
            EditorActionController::class => [
                'catalogue',
                'start',
                // The same action over several records (ADR-162). No new grant
                // and no new runtime: 'batch' plans and 'startBatch' loops the
                // 'start' path, so both re-check `tasks_use` and both get their
                // authorisation from the catalogue, per record.
                'batch',
                'startBatch',
            ],
            // The approvals inbox actions, shared with 'nrllm_runs': the
            // controller scopes visibility by actor (admin/approval grant =>
            // all runs, else own) and the write side is authorised per run by
            // mayActOnRun() — no logic is duplicated for the second module.
            AgentRunController::class => [
                'list',
                'approve',
                'submitInput',
                // Same per-run AGENT_READ gate as in 'nrllm_runs' (ADR-153).
                'show',
            ],
        ],
    ],
];
