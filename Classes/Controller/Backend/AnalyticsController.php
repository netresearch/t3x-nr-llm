<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Analytics\AnalyticsPeriod;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend controller for the LLM usage analytics dashboard.
 *
 * Read-only: all data comes from UsageAnalyticsService (never a repository
 * directly — see Tests/Architecture/ControllerLayerTest). The date range is a
 * GET parameter (`range`), so the page is a plain reload with no AJAX.
 */
#[AsController]
final class AnalyticsController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UsageAnalyticsServiceInterface $analytics,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $this->pageRenderer->addCssFile('EXT:nr_llm/Resources/Public/Css/Backend/Analytics.css');
        $this->pageRenderer->addJsFile('EXT:nr_llm/Resources/Public/JavaScript/Vendor/chart.umd.js');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/Analytics.js');

        // Range arrives as a plain query param (?range=7d); the switcher links
        // are built with buildUriFromRoute below — consistent with the other
        // nr_llm submodules. AnalyticsPeriod normalizes unknown values.
        $rangeParam = $this->request->getQueryParams()['range'] ?? '30d';
        $range = is_string($rangeParam) ? $rangeParam : '30d';
        $period = AnalyticsPeriod::fromPreset($range, new DateTimeImmutable());

        $rangeLinks = [];
        foreach (AnalyticsPeriod::presets() as $presetOption) {
            $rangeLinks[$presetOption] = (string)$this->backendUriBuilder->buildUriFromRoute(
                'nrllm_analytics',
                ['range' => $presetOption],
            );
        }

        $trend = $this->analytics->getDailyTrend($period->from, $period->to);
        $byProvider = $this->analytics->getBreakdownByProvider($period->from, $period->to);
        $byModel = $this->analytics->getBreakdownByModel($period->from, $period->to);
        $byService = $this->analytics->getBreakdownByService($period->from, $period->to);

        $moduleTemplate->assignMultiple([
            'preset'     => $period->preset,
            'presets'    => AnalyticsPeriod::presets(),
            'rangeLinks' => $rangeLinks,
            'from'       => $period->from->format('Y-m-d'),
            'to'         => $period->to->format('Y-m-d'),
            'kpi'        => $this->analytics->getKpiTotals($period->from, $period->to),
            'trend'      => $trend,
            'byProvider' => $byProvider,
            'byModel'    => $byModel,
            'byService'  => $byService,
            'perUser'    => $this->analytics->getPerUserUsage($period->from, $period->to),
            // JSON consumed by Analytics.js (Task 7).
            'chartData'  => json_encode([
                'trend'      => $trend,
                'byProvider' => $byProvider,
                'byModel'    => $byModel,
                'byService'  => $byService,
            ], JSON_THROW_ON_ERROR),
        ]);

        if (method_exists($moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm_analytics',
                displayName: 'LLM - Analytics',
            );
        }

        return $moduleTemplate->renderResponse('Backend/Analytics/Index');
    }
}
