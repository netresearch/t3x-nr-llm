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
use Netresearch\NrLlm\Controller\Backend\UseCasePackController;

/**
 * Backend module registration for nr_llm.
 *
 * Structure (ADR-119, decided in #812):
 *
 *   netresearch_ai            top-level section, shared with the sibling
 *                             extensions; no access check of its own
 *   ├── nrllm_aitasks         editor surface        (access => user)
 *   ├── nrllm_overview        landing page          (access => admin)
 *   ├── nrllm_setup           providers, models, configurations, use-case
 *   ├── nrllm_authoring       tasks, skills, snippets
 *   └── nrllm_operation       tools, MCP, playground, runs, analytics
 *
 * The section replaces 'tools' as the top-level parent; the depth is unchanged
 * (a section held one container holding fourteen entries before, and now holds
 * five entries holding their own).
 *
 * WHY A SECTION AND NOT A CONTAINER UNDER ADMINISTRATION. The module menu drops
 * every top-level module whose own access check FAILS, together with all its
 * children (ADR-131). Under the admin-only 'nrllm' container an editor surface
 * was therefore invisible, and 'nrllm_aitasks' had to be parented to 'web' to
 * be reachable at all. Every further editor surface would have landed there for
 * the same reason, one flat entry at a time. A section carries no 'access' key
 * of its own — exactly like the core's own sections — so it never filters; its
 * children filter individually, and admin-only and editor-facing modules can
 * finally live in one place.
 *
 * WHY THE IDENTIFIER IS VENDOR-SCOPED. Module identifiers merge last-package-
 * wins. A bare 'ai' would be a shared namespace with no owner: the label and
 * icon would depend on package load order, and removing the owning extension
 * would strip the routes of any foreign submodule parented to it.
 *
 * OLD ROUTES. 'nrllm' is no longer a registered identifier, so 'nrllm_overview'
 * carries it as an alias — an alias is shadowed by a real module of the same
 * name, which is why the container had to go first. Backend shortcuts store the
 * module identifier, so they resolve through that alias. ModuleFactory also
 * rewrites 'position' references through aliases, so a foreign module anchored
 * with ['after' => 'nrllm'] keeps its place without changing.
 *
 * Submodule identifiers and explicit paths are unchanged on purpose: a regroup
 * that also renamed the leaves would break every bookmark for no gain.
 *
 * v13 compatibility: each container registers 'nrllm_*' children and carries
 * both 'dependsOnSubmodules' (v13 redirects to the first submodule) and
 * 'showSubmoduleOverview' (v14 renders an overview instead).
 */
return [
    // The shared top-level section. No 'parent', no 'path', no 'access' and no
    // 'controllerActions' — that is the shape of a section rather than a module,
    // and it is what the core's own sections look like. The absent access check
    // is load-bearing, not an omission: see the header.
    //
    // Positioned after 'media' rather than at the end with the admin sections,
    // because the audience that makes the section necessary is editors.
    'netresearch_ai' => [
        'position' => ['after' => 'media'],
        'iconIdentifier' => 'module-nrllm',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_section.xlf',
    ],
    // Setup: what has to exist before anything can run.
    'nrllm_setup' => [
        'parent' => 'netresearch_ai',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-provider',
        'path' => '/module/nrllm/setup',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_setup.xlf',
        'extensionName' => 'NrLlm',
        'appearance' => [
            'dependsOnSubmodules' => true,
        ],
        'showSubmoduleOverview' => true,
    ],
    // Authoring: what the models are asked to do.
    'nrllm_authoring' => [
        'parent' => 'netresearch_ai',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-snippet',
        'path' => '/module/nrllm/authoring',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_authoring.xlf',
        'extensionName' => 'NrLlm',
        'appearance' => [
            'dependsOnSubmodules' => true,
        ],
        'showSubmoduleOverview' => true,
    ],
    // Operation: what is running, and what it cost.
    'nrllm_operation' => [
        'parent' => 'netresearch_ai',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-runs',
        'path' => '/module/nrllm/operation',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_operation.xlf',
        'extensionName' => 'NrLlm',
        'appearance' => [
            'dependsOnSubmodules' => true,
        ],
        'showSubmoduleOverview' => true,
    ],
    // The section's landing page, and the holder of the old container's
    // identifier. 'aliases' resolves backend shortcuts stored against 'nrllm'
    // and re-anchors foreign modules positioned ['after' => 'nrllm'] — both
    // only work because 'nrllm' is no longer registered as a real module, which
    // would shadow the alias.
    'nrllm_overview' => [
        'parent' => 'netresearch_ai',
        'position' => ['before' => '*'],
        'aliases' => ['nrllm'],
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
        'parent' => 'nrllm_setup',
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
        'parent' => 'nrllm_setup',
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
        'parent' => 'nrllm_setup',
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
        'parent' => 'nrllm_authoring',
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
        'parent' => 'nrllm_authoring',
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
    // Use-case onboarding - child of main module, listed BEFORE the setup
    // wizard because it asks the earlier question: what do you want to do?
    // (ADR-163). It recommends a pack; the wizard below it stays the technical
    // route and every screen here links to it.
    //
    // Shares the wizard's icon deliberately: the two are one entry path, and a
    // second wizard-family glyph would suggest a second kind of thing.
    'nrllm_usecase' => [
        'parent' => 'nrllm_setup',
        'access' => 'admin',
        'iconIdentifier' => 'module-nrllm-wizard',
        'path' => '/module/nrllm/use-case',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_usecase.xlf',
        'extensionName' => 'NrLlm',
        'controllerActions' => [
            UseCasePackController::class => [
                'index',
                'show',
                'install',
            ],
        ],
    ],
    // Setup wizard - child of main module
    // Note: AJAX actions (detect, test, discover, generate, save) are registered via AjaxRoutes.php
    'nrllm_wizard' => [
        'parent' => 'nrllm_setup',
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
        'parent' => 'nrllm_authoring',
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
        'parent' => 'nrllm_operation',
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
        'parent' => 'nrllm_operation',
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
        'parent' => 'nrllm_operation',
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
        'parent' => 'nrllm_operation',
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
        'parent' => 'nrllm_operation',
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
        'parent' => 'netresearch_ai',
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
