<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\Widgets\DataProvider\MonthlyCostDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\RequestsByProviderDataProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Dashboard\Widgets\BarChartWidget;
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
};
