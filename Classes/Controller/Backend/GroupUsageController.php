<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Service\Analytics\AnalyticsPeriod;
use Netresearch\NrLlm\Service\Analytics\UsageAudienceResolver;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The AI usage of the members of the backend groups assigned to a head
 * editor (ADR-277), for holders of the `usage_view_groups` grant.
 *
 * A module of its own with `access => user`, not an action of the admin-only
 * Analytics module: it reuses the analytics service, its charts and its
 * template partials, and assigns nothing else. Fallback rescues, provider
 * health, the billing report and its export are never computed here, so
 * they cannot leak. Every figure comes from
 * {@see UsageAnalyticsServiceInterface::forUsers()} with the resolved
 * audience; only an administrator gets the unrestricted service.
 *
 * Read-only, no AJAX: the range is a GET parameter and the chart data is
 * embedded in the page.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class GroupUsageController extends ActionController
{
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;

    public const ROUTE = 'nrllm_groupusage';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UsageAnalyticsServiceInterface $analytics,
        private readonly UsageAudienceResolver $audiences,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function indexAction(): ResponseInterface
    {
        // The module switch alone never grants: the resolver checks the grant
        // before it names anybody, and a denial is a 403 with no data.
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $audience    = $this->audiences->forUser($backendUser instanceof BackendUserAuthentication ? $backendUser : null);
        if (!$audience->permitted) {
            return $this->denyWithoutGrantHtml(BackendUserGrant::USAGE_VIEW_GROUPS) ?? new HtmlResponse('', 403);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($moduleTemplate, $this->request);

        $moduleTemplate->assignMultiple([
            'everyone'       => $audience->everyone,
            'assignedGroups' => $audience->assignedGroups,
            'memberCount'    => count($audience->beUserUids),
        ]);

        if (!$audience->everyone && $audience->assignedGroups === []) {
            return $moduleTemplate->renderResponse('Backend/GroupUsage/Index');
        }

        $this->pageRenderer->addCssFile('EXT:nr_llm/Resources/Public/Css/Backend/Analytics.css');
        $this->pageRenderer->addJsFile('EXT:nr_llm/Resources/Public/JavaScript/Vendor/chart.umd.js');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/Analytics.js');

        $analytics = $audience->everyone ? $this->analytics : $this->analytics->forUsers($audience->beUserUids);

        $rangeParam = $this->request->getQueryParams()['range'] ?? '30d';
        $period     = AnalyticsPeriod::fromPreset(is_string($rangeParam) ? $rangeParam : '30d', new DateTimeImmutable());

        $rangeLinks = [];
        foreach (AnalyticsPeriod::presets() as $presetOption) {
            $rangeLinks[$presetOption] = (string) $this->backendUriBuilder->buildUriFromRoute(self::ROUTE, ['range' => $presetOption]);
        }

        $trend      = $analytics->getDailyTrend($period->from, $period->to);
        $byProvider = $analytics->getBreakdownByProvider($period->from, $period->to);
        $byModel    = $analytics->getBreakdownByModel($period->from, $period->to);
        $byService  = $analytics->getBreakdownByService($period->from, $period->to);
        $bySource   = $analytics->getBreakdownBySourceExtension($period->from, $period->to);

        $moduleTemplate->assignMultiple([
            'preset'     => $period->preset,
            'presets'    => AnalyticsPeriod::presets(),
            'rangeLinks' => $rangeLinks,
            'from'       => $period->from->format('Y-m-d'),
            'to'         => $period->to->format('Y-m-d'),
            'kpi'        => $analytics->getKpiTotals($period->from, $period->to),
            'bySource'   => $bySource,
            'perUser'    => $analytics->getPerUserUsage($period->from, $period->to),
            // Same encoding as the Analytics module (see AnalyticsController):
            // JSON_HEX_* keeps a label from breaking out of the script element.
            'chartData' => json_encode([
                'trend'      => $trend,
                'byProvider' => $byProvider,
                'byModel'    => $byModel,
                'byService'  => $byService,
                'bySource'   => $bySource,
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);

        return $moduleTemplate->renderResponse('Backend/GroupUsage/Index');
    }
}
