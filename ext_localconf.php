<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Form\Element\ModelIdElement;
use Netresearch\NrLlm\Form\FieldControl\AccessCheckedAddRecord;
use Netresearch\NrLlm\Form\FieldControl\ManageRecordsControl;
use Netresearch\NrLlm\Form\FieldWizard\ChoiceHelpWizard;
use Netresearch\NrLlm\Form\FieldWizard\ModelConstraintsWizard;
use Netresearch\NrLlm\Hook\ModelIdentityChangeHook;
use Netresearch\NrLlm\Hook\ProviderEndpointNormalizationHook;
use Netresearch\NrLlm\Hook\SkillDeletionGuardHook;
use Netresearch\NrLlm\Hook\SkillDisabledByHook;
use Netresearch\NrLlm\Hook\TranslationCacheFlushHook;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentSaveHook;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillHook;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') || exit;

(static function (): void {
    // Cache configuration (also in Configuration/Caching.php for TYPO3 v14+)
    // No backend specified — TYPO3 uses the instance's default cache backend,
    // which respects Redis/Valkey/Memcached if configured by the admin.
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_responses'] ??= [
        'frontend' => VariableFrontend::class,
        'options'  => [
            'defaultLifetime' => 3600,
        ],
        'groups' => ['nrllm'],
    ];

    // Short-lived reachability cache (overview provider dots).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_reachability'] ??= [
        'frontend' => VariableFrontend::class,
        'options'  => [
            'defaultLifetime' => 60,
        ],
        'groups' => ['nrllm'],
    ];

    // Per-provider circuit breaker state (ADR-063). The store writes its own
    // per-entry lifetime; this default applies only to entries written without.
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_circuit'] ??= [
        'frontend' => VariableFrontend::class,
        'options'  => [
            'defaultLifetime' => 300,
        ],
        'groups' => ['nrllm'],
    ];

    // Short-lived provider health snapshot (ADR-063).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_health'] ??= [
        'frontend' => VariableFrontend::class,
        'options'  => [
            'defaultLifetime' => 60,
        ],
        'groups' => ['nrllm'],
    ];

    // Request idempotency store (ADR-063). The middleware writes its own
    // per-entry TTL (the idempotency window).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_idempotency'] ??= [
        'frontend' => VariableFrontend::class,
        'options'  => [
            'defaultLifetime' => 86400,
        ],
        'groups' => ['nrllm'],
    ];

    // Register custom TCA renderType for model_id field with API fetch
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1741427200] = [
        'nodeName' => 'modelIdWithFetch',
        'priority' => 40,
        'class'    => ModelIdElement::class,
    ];

    // Register field wizard for model constraint detection on configuration form
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1741427201] = [
        'nodeName' => 'modelConstraintsWizard',
        'priority' => 40,
        'class'    => ModelConstraintsWizard::class,
    ];

    // Record selects: the chosen option's description under the select, and
    // create / manage controls shown only to users who may use them
    // (ADR-255).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1762160401] = [
        'nodeName' => 'nrllmChoiceHelp',
        'priority' => 40,
        'class'    => ChoiceHelpWizard::class,
    ];
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1762160402] = [
        'nodeName' => 'nrllmAddRecord',
        'priority' => 40,
        'class'    => AccessCheckedAddRecord::class,
    ];
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1762160404] = [
        'nodeName' => 'nrllmManageRecords',
        'priority' => 40,
        'class'    => ManageRecordsControl::class,
    ];

    // Normalize tx_nrllm_provider.endpoint_url to the adapter's canonical base URL
    // when a provider is created/edited through the TCA record editor, so the
    // manual write path stores a working base URL just like the Setup Wizard (#300).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = ProviderEndpointNormalizationHook::class;

    // Flush the cached translations when a glossary record is saved or
    // deleted (ADR-209).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = TranslationCacheFlushHook::class;
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
        = TranslationCacheFlushHook::class;

    // A disable through the DataHandler is an administrator's decision; a
    // re-enable clears the sync's mark (ADR-214 item 3).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = SkillDisabledByHook::class;

    // An attached skill cannot be deleted: deleting it would lift the tool
    // restriction it imposes on a run (ADR-214 item 3).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
        = SkillDeletionGuardHook::class;

    // An empty, opted-in field is queued for filling after a save (ADR-237).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = FieldFillHook::class;

    // A model record whose model id or provider changes forgets the reasoning
    // profile discovered for the old one (ADR-275).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = ModelIdentityChangeHook::class;

    // Saving a remote A2A agent confirms a card whose interface or
    // capabilities changed on re-import (spec 051 A2A-C2).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = A2aRemoteAgentSaveHook::class;

    // Dedicated dashboard widget group for the agentic / governance / telemetry
    // widgets, so they do not scatter into the built-in 'general' group. Inert
    // without typo3/cms-dashboard installed (the array is simply never read).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['dashboard']['widgetGroups']['nrllm'] ??= [
        'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widgetGroup.nrllm.title',
    ];

    // Capability grants for human backend users (ADR-130): assigned per
    // backend GROUP through the be_groups access lists, checked via
    // BackendUserAuthentication::check('custom_options', ...). Item keys must
    // not contain ':', '|' or ',' — TYPO3 strips those when rendering the
    // select, so a colon-namespaced key would be stored mangled and silently
    // deny (see BackendUserGrant).
    // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
    $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['tx_nrllm_grants'] = [
        'header' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:grants.header',
        'items'  => [
            BackendUserGrant::TASKS_USE->value => [
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:grants.tasksUse',
                'module-nrllm',
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:grants.tasksUse.description',
            ],
            BackendUserGrant::AGENT_APPROVE->value => [
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:grants.agentApprove',
                'module-nrllm',
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:grants.agentApprove.description',
            ],
            // ADR-277: personal cost data of other users. Shows only the
            // members of the groups an administrator assigned in the group
            // record's "AI usage visible for groups" field.
            BackendUserGrant::USAGE_VIEW_GROUPS->value => [
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:grants.usageViewGroups',
                'module-nrllm-analytics',
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:grants.usageViewGroups.description',
            ],
        ],
    ];
})();
