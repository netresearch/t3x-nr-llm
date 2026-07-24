<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Widgets\DataProvider\AgentRunsByStatusDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\AverageLatencyDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\GovernanceBlocksOverTimeDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\MonthlyCostDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\RequestsByProviderDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\RequestSuccessRateDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\RunsAwaitingApprovalDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\RunTerminationReasonsDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\ToolDenialsByReasonDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\ToolUsageByNameDataProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Dashboard\Widgets\BarChartWidget;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconWidget;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Dashboard widget registration for nr-llm.
 *
 * Imported conditionally from Configuration/Services.php only when
 * typo3/cms-dashboard is installed. This is a PHP config file (not YAML) because
 * TYPO3 loads Configuration/Services.php with a standalone Symfony PhpFileLoader
 * that has no YAML loader in its resolver, so a `.yaml` import cannot be
 * resolved from there.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->set(MonthlyCostDataProvider::class);
    $services->set(RequestsByProviderDataProvider::class);

    $services->set('dashboard.widget.nrllm.monthly_cost', NumberWithIconWidget::class)
        ->arg('$dataProvider', service(MonthlyCostDataProvider::class))
        ->arg('$options', [
            'icon'     => 'actions-currency',
            'title'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.monthly_cost.title',
            'subtitle' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.monthly_cost.subtitle',
        ])
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-monthly-cost',
            'groupNames'     => 'general',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.monthly_cost.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.monthly_cost.description',
            'iconIdentifier' => 'actions-currency',
            'height'         => 'small',
            'width'          => 'small',
        ]);

    $services->set('dashboard.widget.nrllm.requests_by_provider', BarChartWidget::class)
        ->arg('$dataProvider', service(RequestsByProviderDataProvider::class))
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-requests-by-provider',
            'groupNames'     => 'general',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.requests_by_provider.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.requests_by_provider.description',
            'iconIdentifier' => 'content-elements-mailform',
            'height'         => 'medium',
            'width'          => 'medium',
        ]);

    // --- Agentic / governance / telemetry widgets (group: nrllm) ---

    $services->set(AgentRunsByStatusDataProvider::class);
    $services->set(RunsAwaitingApprovalDataProvider::class);
    $services->set(RunTerminationReasonsDataProvider::class);
    $services->set(RequestSuccessRateDataProvider::class);
    $services->set(AverageLatencyDataProvider::class);

    // Agent runs by lifecycle status over the last 30 days (doughnut).
    $services->set('dashboard.widget.nrllm.agent_runs_by_status', DoughnutChartWidget::class)
        ->arg('$dataProvider', service(AgentRunsByStatusDataProvider::class))
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-agent-runs-by-status',
            'groupNames'     => 'nrllm',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.agent_runs_by_status.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.agent_runs_by_status.description',
            'iconIdentifier' => 'content-widget-chart-pie',
            'height'         => 'medium',
            'width'          => 'medium',
        ]);

    // Runs currently suspended waiting for a human approval (live gauge).
    // NumberWithIconWidget carries no footer button in TYPO3 v14, so the tile
    // is the count alone; the Agent Runs approvals module (nrllm_runs) is where
    // an admin acts on them.
    $services->set('dashboard.widget.nrllm.runs_awaiting_approval', NumberWithIconWidget::class)
        ->arg('$dataProvider', service(RunsAwaitingApprovalDataProvider::class))
        ->arg('$options', [
            'icon'     => 'actions-check',
            'title'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.runs_awaiting_approval.title',
            'subtitle' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.runs_awaiting_approval.subtitle',
        ])
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-runs-awaiting-approval',
            'groupNames'     => 'nrllm',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.runs_awaiting_approval.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.runs_awaiting_approval.description',
            'iconIdentifier' => 'actions-check',
            'height'         => 'small',
            'width'          => 'small',
        ]);

    // Why terminated agent runs ended, over the last 30 days (bar).
    $services->set('dashboard.widget.nrllm.run_termination_reasons', BarChartWidget::class)
        ->arg('$dataProvider', service(RunTerminationReasonsDataProvider::class))
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-run-termination-reasons',
            'groupNames'     => 'nrllm',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.run_termination_reasons.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.run_termination_reasons.description',
            'iconIdentifier' => 'content-widget-chart-bar',
            'height'         => 'medium',
            'width'          => 'medium',
        ]);

    // Provider pipeline success rate (%, last 7 days) from telemetry.
    $services->set('dashboard.widget.nrllm.request_success_rate', NumberWithIconWidget::class)
        ->arg('$dataProvider', service(RequestSuccessRateDataProvider::class))
        ->arg('$options', [
            'icon'     => 'actions-check-circle',
            'title'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.request_success_rate.title',
            'subtitle' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.request_success_rate.subtitle',
        ])
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-request-success-rate',
            'groupNames'     => 'nrllm',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.request_success_rate.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.request_success_rate.description',
            'iconIdentifier' => 'actions-check-circle',
            'height'         => 'small',
            'width'          => 'small',
        ]);

    // Mean end-to-end provider pipeline latency (ms, last 7 days) from telemetry.
    $services->set('dashboard.widget.nrllm.average_latency', NumberWithIconWidget::class)
        ->arg('$dataProvider', service(AverageLatencyDataProvider::class))
        ->arg('$options', [
            'icon'     => 'actions-clock',
            'title'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.average_latency.title',
            'subtitle' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.average_latency.subtitle',
        ])
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-average-latency',
            'groupNames'     => 'nrllm',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.average_latency.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.average_latency.description',
            'iconIdentifier' => 'actions-clock',
            'height'         => 'small',
            'width'          => 'small',
        ]);

    // --- Governance / tool-usage widgets (group: nrllm), backed by
    // tx_nrllm_governance_event ---

    $services->set(GovernanceBlocksOverTimeDataProvider::class);
    $services->set(ToolDenialsByReasonDataProvider::class);
    $services->set(ToolUsageByNameDataProvider::class);

    // Governance blocks by kind over the last 30 days (bar).
    $services->set('dashboard.widget.nrllm.governance_blocks', BarChartWidget::class)
        ->arg('$dataProvider', service(GovernanceBlocksOverTimeDataProvider::class))
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-governance-blocks',
            'groupNames'     => 'nrllm',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.governance_blocks.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.governance_blocks.description',
            'iconIdentifier' => 'content-widget-chart-bar',
            'height'         => 'medium',
            'width'          => 'medium',
        ]);

    // Tool-gate denials by reason over the last 30 days (bar).
    $services->set('dashboard.widget.nrllm.tool_denials_by_reason', BarChartWidget::class)
        ->arg('$dataProvider', service(ToolDenialsByReasonDataProvider::class))
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-tool-denials-by-reason',
            'groupNames'     => 'nrllm',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.tool_denials.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.tool_denials.description',
            'iconIdentifier' => 'content-widget-chart-bar',
            'height'         => 'medium',
            'width'          => 'medium',
        ]);

    // Tool-gate decisions by tool name over the last 30 days (bar).
    $services->set('dashboard.widget.nrllm.tool_usage_by_name', BarChartWidget::class)
        ->arg('$dataProvider', service(ToolUsageByNameDataProvider::class))
        ->tag('dashboard.widget', [
            'identifier'     => 'nrllm-tool-usage-by-name',
            'groupNames'     => 'nrllm',
            'title'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.tool_usage.title',
            'description'    => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.tool_usage.description',
            'iconIdentifier' => 'content-widget-chart-bar',
            'height'         => 'medium',
            'width'          => 'medium',
        ]);
};
