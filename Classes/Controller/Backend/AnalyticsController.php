<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Analytics\AnalyticsPeriod;
use Netresearch\NrLlm\Service\Analytics\BillingMarkup;
use Netresearch\NrLlm\Service\Analytics\BillingMonth;
use Netresearch\NrLlm\Service\Analytics\BillingReportCsv;
use Netresearch\NrLlm\Service\Analytics\FallbackRescueReport;
use Netresearch\NrLlm\Service\Analytics\MonthlyBillingReportService;
use Netresearch\NrLlm\Service\Analytics\ProviderHealthReport;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend controller for the LLM usage analytics dashboard.
 *
 * Read-only: all data comes from UsageAnalyticsService (never a repository
 * directly — see Tests/Architecture/ControllerLayerTest). The date range is a
 * GET parameter (`range`), so the page is a plain reload with no AJAX.
 *
 * The monthly billing report (ADR-231) is a second page of the same module:
 * `billing` shows one calendar month (`month=YYYY-MM`), `exportBilling`
 * downloads it as CSV. Both refuse a non-admin themselves, on top of the
 * module's `access => admin`: the export carries user names and costs.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class AnalyticsController extends ActionController
{
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UsageAnalyticsServiceInterface $analytics,
        private readonly FallbackRescueReport $fallbackRescues,
        private readonly ProviderHealthReport $providerHealth,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly MonthlyBillingReportService $billingReports,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($moduleTemplate, $this->request);

        $this->pageRenderer->addCssFile('EXT:nr_llm/Resources/Public/Css/Backend/Analytics.css');
        $this->pageRenderer->addJsFile('EXT:nr_llm/Resources/Public/JavaScript/Vendor/chart.umd.js');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/Analytics.js');

        // Range arrives as a plain query param (?range=7d); the switcher links
        // are built with buildUriFromRoute below — consistent with the other
        // nr_llm submodules. AnalyticsPeriod normalizes unknown values.
        $rangeParam = $this->request->getQueryParams()['range'] ?? '30d';
        $range      = is_string($rangeParam) ? $rangeParam : '30d';
        $period     = AnalyticsPeriod::fromPreset($range, new DateTimeImmutable());

        $rangeLinks = [];
        foreach (AnalyticsPeriod::presets() as $presetOption) {
            $rangeLinks[$presetOption] = (string) $this->backendUriBuilder->buildUriFromRoute(
                'nrllm_analytics',
                ['range' => $presetOption],
            );
        }

        $trend      = $this->analytics->getDailyTrend($period->from, $period->to);
        $byProvider = $this->analytics->getBreakdownByProvider($period->from, $period->to);
        $byModel    = $this->analytics->getBreakdownByModel($period->from, $period->to);
        $byService  = $this->analytics->getBreakdownByService($period->from, $period->to);
        $bySource   = $this->analytics->getBreakdownBySourceExtension($period->from, $period->to);

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
            // Who called: the extension key a consumer named via
            // withCallerSource() (ADR-178). The compatibility layer tags every
            // bridged third-party call, so this is the AI inventory of the
            // installation, priced.
            'bySource'   => $bySource,
            'perUser'    => $this->analytics->getPerUserUsage($period->from, $period->to),
            'billingUrl' => $this->billingUrl('billing', BillingMonth::previous(new DateTimeImmutable())),
            // Reads tx_nrllm_telemetry, not the usage table: the runs a sibling
            // configuration answered for after the requested one failed.
            'rescues' => $this->fallbackRescues->rescuesSince($period->from),
            // Ignores the selected range on purpose: health is a rolling
            // telemetry window (ADR-063) and circuit state is live cache
            // state — neither can be re-cut to a 90-day report period.
            'health' => $this->providerHealth->readout(),
            // JSON consumed by Analytics.js, embedded in a <script> tag via
            // f:format.raw(). JSON_HEX_* neutralises tag-breaking characters
            // (e.g. a model_id/provider label containing "</script>") so the
            // raw output cannot break out of the script element (XSS-safe).
            'chartData' => json_encode([
                'trend'      => $trend,
                'byProvider' => $byProvider,
                'byModel'    => $byModel,
                'byService'  => $byService,
                'bySource'   => $bySource,
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);

        if (method_exists($moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm_analytics',
                displayName: 'LLM - Analytics',
            );
        }

        return $moduleTemplate->renderResponse('Backend/Analytics/Index');
    }

    /**
     * One calendar month for billing: cost with markup, active and entitled
     * editors, results by kind. Default: the previous complete month.
     */
    public function billingAction(): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($moduleTemplate, $this->request);
        $this->pageRenderer->addCssFile('EXT:nr_llm/Resources/Public/Css/Backend/Analytics.css');

        $now    = new DateTimeImmutable();
        $month  = BillingMonth::fromParameter($this->request->getQueryParams()['month'] ?? null, $now);
        $report = $this->billingReports->build($month, $now);

        $monthLinks = [];
        foreach (BillingMonth::choices($now) as $choice) {
            $monthLinks[] = [
                'key'     => $choice->key(),
                'start'   => $choice->start,
                'url'     => $this->billingUrl('billing', $choice),
                'current' => $choice->key() === $month->key(),
            ];
        }

        $moduleTemplate->assignMultiple([
            'report'       => $report,
            'monthLinks'   => $monthLinks,
            'maxMarkup'    => BillingMarkup::MAX_PERCENT,
            'exportUrl'    => $this->billingUrl('exportBilling', $month),
            'exportName'   => BillingReportCsv::fileName($month),
            'analyticsUrl' => (string) $this->backendUriBuilder->buildUriFromRoute('nrllm_analytics'),
        ]);

        return $moduleTemplate->renderResponse('Backend/Analytics/Billing');
    }

    /**
     * The same month as one CSV download (see {@see BillingReportCsv}).
     */
    public function exportBillingAction(): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $now    = new DateTimeImmutable();
        $month  = BillingMonth::fromParameter($this->request->getQueryParams()['month'] ?? null, $now);
        $report = $this->billingReports->build($month, $now);

        $response = new Response();
        $response->getBody()->write(BillingReportCsv::render($report));

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . BillingReportCsv::fileName($month) . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * A module URL; the backend UriBuilder adds the route token the
     * dispatcher checks.
     */
    private function billingUrl(string $action, BillingMonth $month): string
    {
        return (string) $this->backendUriBuilder->buildUriFromRoute('nrllm_analytics', [
            'controller' => 'Backend\\Analytics',
            'action'     => $action,
            'month'      => $month->key(),
        ]);
    }
}
