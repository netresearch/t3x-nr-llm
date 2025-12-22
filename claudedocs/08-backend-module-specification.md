# Backend Module Specification - nr-llm Extension

> Analysis Date: 2025-12-22
> Purpose: Complete backend administration interface design for LLM management

---

## 1. Backend Module Architecture

### Module Overview

```
TYPO3 Backend → Tools → AI/LLM Management
├── Dashboard          (Overview & Quick Stats)
├── Providers          (Provider Configuration)
├── Prompts            (Template Management)
├── Usage              (Reports & Analytics)
└── Settings           (Global Configuration)
```

### Design Principles
- **Responsive**: Mobile-friendly admin interface
- **Performance**: Lazy loading, pagination, async data
- **Accessibility**: WCAG 2.1 AA compliant
- **Security**: CSRF protection, permission checks
- **UX**: Intuitive workflows, inline help

---

## 2. Module Registration

### Configuration/Backend/Modules.php

```php
<?php
declare(strict_types=1);

use Netresearch\NrLlm\Backend\Controller\DashboardController;
use Netresearch\NrLlm\Backend\Controller\ProvidersController;
use Netresearch\NrLlm\Backend\Controller\PromptsController;
use Netresearch\NrLlm\Backend\Controller\UsageController;
use Netresearch\NrLlm\Backend\Controller\SettingsController;

return [
    'tools_NrLlmManagement' => [
        'parent' => 'tools',
        'position' => ['after' => 'extensionmanager'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/tools/llm',
        'labels' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'NrLlm',
        'iconIdentifier' => 'module-nr-llm',
        'navigationComponent' => '@typo3/backend/page-tree/page-tree-element',
        'inheritNavigationComponentFromMainModule' => false,
        'controllerActions' => [
            DashboardController::class => [
                'index',
                'stats',
                'quickActions',
            ],
            ProvidersController::class => [
                'list',
                'edit',
                'testConnection',
                'toggleStatus',
            ],
            PromptsController::class => [
                'list',
                'edit',
                'create',
                'delete',
                'preview',
            ],
            UsageController::class => [
                'overview',
                'reports',
                'export',
                'charts',
            ],
            SettingsController::class => [
                'general',
                'quotas',
                'cache',
                'security',
            ],
        ],
    ],
];
```

---

## 3. Controller Implementations

### DashboardController

**Purpose**: Overview, quick stats, health monitoring

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Backend\Controller;

use Netresearch\NrLlm\Service\AnalyticsService;
use Netresearch\NrLlm\Service\ProviderHealthService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Dashboard controller for LLM management overview
 */
class DashboardController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AnalyticsService $analytics,
        private readonly ProviderHealthService $healthService
    ) {}

    /**
     * Main dashboard view
     */
    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        // Gather dashboard data
        $data = [
            'todayStats' => $this->analytics->getTodayStats(),
            'weekStats' => $this->analytics->getWeekStats(),
            'monthStats' => $this->analytics->getMonthStats(),
            'topUsers' => $this->analytics->getTopUsers(10),
            'providerHealth' => $this->healthService->getHealthStatus(),
            'cacheMetrics' => $this->analytics->getCacheMetrics(),
            'costSummary' => $this->analytics->getCostSummary(),
            'recentRequests' => $this->analytics->getRecentRequests(20),
        ];

        $moduleTemplate->assignMultiple($data);

        return $moduleTemplate->renderResponse('Dashboard/Index');
    }

    /**
     * Detailed stats endpoint (AJAX)
     */
    public function statsAction(): ResponseInterface
    {
        $period = $this->request->getArgument('period') ?? 'today';
        $stats = match($period) {
            'today' => $this->analytics->getTodayStats(),
            'week' => $this->analytics->getWeekStats(),
            'month' => $this->analytics->getMonthStats(),
            'year' => $this->analytics->getYearStats(),
            default => $this->analytics->getTodayStats(),
        };

        return $this->jsonResponse($stats);
    }

    /**
     * Quick actions for common tasks
     */
    public function quickActionsAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $actions = [
            'clearCache' => [
                'label' => 'Clear Response Cache',
                'icon' => 'actions-delete',
                'uri' => $this->uriBuilder->uriFor('clearCache', [], 'Settings'),
            ],
            'testProviders' => [
                'label' => 'Test All Providers',
                'icon' => 'actions-play',
                'uri' => $this->uriBuilder->uriFor('testConnection', [], 'Providers'),
            ],
            'exportUsage' => [
                'label' => 'Export Usage Report',
                'icon' => 'actions-download',
                'uri' => $this->uriBuilder->uriFor('export', [], 'Usage'),
            ],
        ];

        $moduleTemplate->assign('actions', $actions);

        return $moduleTemplate->renderResponse('Dashboard/QuickActions');
    }
}
```

---

### ProvidersController

**Purpose**: Provider configuration and management

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Backend\Controller;

use Netresearch\NrLlm\Domain\Model\ProviderConfiguration;
use Netresearch\NrLlm\Domain\Repository\ProviderConfigurationRepository;
use Netresearch\NrLlm\Service\ProviderFactory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Provider management controller
 */
class ProvidersController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ProviderConfigurationRepository $configRepository,
        private readonly ProviderFactory $providerFactory
    ) {}

    /**
     * List all configured providers
     */
    public function listAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $providers = $this->configRepository->findAll();
        $availableProviders = $this->providerFactory->getAvailableProviderTypes();

        $moduleTemplate->assignMultiple([
            'providers' => $providers,
            'availableProviders' => $availableProviders,
            'healthStatus' => $this->getProviderHealthStatus($providers),
        ]);

        return $moduleTemplate->renderResponse('Providers/List');
    }

    /**
     * Edit provider configuration
     */
    public function editAction(
        ?ProviderConfiguration $provider = null
    ): ResponseInterface {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        if ($provider === null) {
            $provider = new ProviderConfiguration();
        }

        $moduleTemplate->assignMultiple([
            'provider' => $provider,
            'providerTypes' => $this->providerFactory->getAvailableProviderTypes(),
            'models' => $this->getAvailableModels($provider->getType()),
        ]);

        return $moduleTemplate->renderResponse('Providers/Edit');
    }

    /**
     * Save provider configuration
     */
    public function updateAction(ProviderConfiguration $provider): ResponseInterface
    {
        // Encrypt API key before saving
        if ($provider->getApiKey()) {
            $encrypted = $this->encryptApiKey($provider->getApiKey());
            $provider->setApiKey($encrypted);
        }

        $this->configRepository->update($provider);

        $this->addFlashMessage(
            'Provider configuration saved successfully',
            'Success',
            \TYPO3\CMS\Core\Messaging\FlashMessage::OK
        );

        return $this->redirect('list');
    }

    /**
     * Test provider connection (AJAX)
     */
    public function testConnectionAction(): ResponseInterface
    {
        $providerId = $this->request->getArgument('provider');
        $provider = $this->configRepository->findByUid($providerId);

        try {
            $instance = $this->providerFactory->createFromConfiguration($provider);
            $result = $instance->testConnection();

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Connection successful',
                'latency' => $result['latency'],
                'model' => $result['model'],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Toggle provider active status (AJAX)
     */
    public function toggleStatusAction(): ResponseInterface
    {
        $providerId = $this->request->getArgument('provider');
        $provider = $this->configRepository->findByUid($providerId);

        $provider->setIsActive(!$provider->isActive());
        $this->configRepository->update($provider);

        return $this->jsonResponse([
            'success' => true,
            'isActive' => $provider->isActive(),
        ]);
    }

    private function getProviderHealthStatus(array $providers): array
    {
        $status = [];
        foreach ($providers as $provider) {
            try {
                $instance = $this->providerFactory->createFromConfiguration($provider);
                $health = $instance->checkHealth();
                $status[$provider->getUid()] = $health;
            } catch (\Throwable $e) {
                $status[$provider->getUid()] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $status;
    }
}
```

---

### PromptsController

**Purpose**: Prompt template management

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Backend\Controller;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository;
use Netresearch\NrLlm\Service\PromptValidator;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Prompt template management controller
 */
class PromptsController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PromptTemplateRepository $promptRepository,
        private readonly PromptValidator $validator
    ) {}

    /**
     * List all prompt templates
     */
    public function listAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $prompts = $this->promptRepository->findAll();
        $groupedByFeature = $this->groupPromptsByFeature($prompts);

        $moduleTemplate->assignMultiple([
            'prompts' => $prompts,
            'groupedPrompts' => $groupedByFeature,
            'features' => $this->getAvailableFeatures(),
        ]);

        return $moduleTemplate->renderResponse('Prompts/List');
    }

    /**
     * Create new prompt template
     */
    public function createAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $prompt = new PromptTemplate();

        $moduleTemplate->assignMultiple([
            'prompt' => $prompt,
            'features' => $this->getAvailableFeatures(),
            'providers' => $this->getAvailableProviders(),
        ]);

        return $moduleTemplate->renderResponse('Prompts/Edit');
    }

    /**
     * Edit existing prompt template
     */
    public function editAction(PromptTemplate $prompt): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $moduleTemplate->assignMultiple([
            'prompt' => $prompt,
            'features' => $this->getAvailableFeatures(),
            'providers' => $this->getAvailableProviders(),
            'validationResult' => $this->validator->validate($prompt),
        ]);

        return $moduleTemplate->renderResponse('Prompts/Edit');
    }

    /**
     * Save prompt template
     */
    public function updateAction(PromptTemplate $prompt): ResponseInterface
    {
        $validationResult = $this->validator->validate($prompt);

        if (!$validationResult->isValid()) {
            foreach ($validationResult->getErrors() as $error) {
                $this->addFlashMessage(
                    $error->getMessage(),
                    'Validation Error',
                    \TYPO3\CMS\Core\Messaging\FlashMessage::ERROR
                );
            }
            return $this->redirect('edit', null, null, ['prompt' => $prompt]);
        }

        if ($prompt->getUid()) {
            $this->promptRepository->update($prompt);
        } else {
            $this->promptRepository->add($prompt);
        }

        $this->addFlashMessage(
            'Prompt template saved successfully',
            'Success',
            \TYPO3\CMS\Core\Messaging\FlashMessage::OK
        );

        return $this->redirect('list');
    }

    /**
     * Delete prompt template
     */
    public function deleteAction(PromptTemplate $prompt): ResponseInterface
    {
        $this->promptRepository->remove($prompt);

        $this->addFlashMessage(
            'Prompt template deleted successfully',
            'Success',
            \TYPO3\CMS\Core\Messaging\FlashMessage::OK
        );

        return $this->redirect('list');
    }

    /**
     * Preview prompt with test data (AJAX)
     */
    public function previewAction(): ResponseInterface
    {
        $templateText = $this->request->getArgument('template');
        $testData = $this->request->getArgument('testData');

        try {
            $rendered = $this->validator->renderPreview($templateText, $testData);

            return $this->jsonResponse([
                'success' => true,
                'rendered' => $rendered,
                'tokenEstimate' => $this->estimateTokens($rendered),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
```

---

### UsageController

**Purpose**: Usage reports, analytics, and export

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Backend\Controller;

use Netresearch\NrLlm\Service\AnalyticsService;
use Netresearch\NrLlm\Service\UsageReportGenerator;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Usage analytics and reporting controller
 */
class UsageController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AnalyticsService $analytics,
        private readonly UsageReportGenerator $reportGenerator
    ) {}

    /**
     * Usage overview
     */
    public function overviewAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $period = $this->request->getArgument('period') ?? 'week';
        $groupBy = $this->request->getArgument('groupBy') ?? 'day';

        $data = [
            'summary' => $this->analytics->getSummary($period),
            'byProvider' => $this->analytics->getUsageByProvider($period),
            'byFeature' => $this->analytics->getUsageByFeature($period),
            'byUser' => $this->analytics->getUsageByUser($period),
            'costBreakdown' => $this->analytics->getCostBreakdown($period),
            'timeline' => $this->analytics->getTimeline($period, $groupBy),
        ];

        $moduleTemplate->assignMultiple($data);

        return $moduleTemplate->renderResponse('Usage/Overview');
    }

    /**
     * Detailed reports
     */
    public function reportsAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $reportType = $this->request->getArgument('type') ?? 'summary';
        $filters = $this->request->getArguments();

        $report = $this->reportGenerator->generate($reportType, $filters);

        $moduleTemplate->assignMultiple([
            'report' => $report,
            'reportType' => $reportType,
            'filters' => $filters,
            'availableReportTypes' => $this->getAvailableReportTypes(),
        ]);

        return $moduleTemplate->renderResponse('Usage/Reports');
    }

    /**
     * Export usage data
     */
    public function exportAction(): ResponseInterface
    {
        $format = $this->request->getArgument('format') ?? 'csv';
        $period = $this->request->getArgument('period') ?? 'month';
        $filters = $this->request->getArguments();

        $data = $this->analytics->getExportData($period, $filters);

        return match($format) {
            'csv' => $this->exportCsv($data),
            'json' => $this->exportJson($data),
            'xlsx' => $this->exportExcel($data),
            default => $this->exportCsv($data),
        };
    }

    /**
     * Chart data endpoint (AJAX)
     */
    public function chartsAction(): ResponseInterface
    {
        $chartType = $this->request->getArgument('chart');
        $period = $this->request->getArgument('period') ?? 'week';

        $chartData = match($chartType) {
            'requests' => $this->analytics->getRequestsChartData($period),
            'costs' => $this->analytics->getCostsChartData($period),
            'providers' => $this->analytics->getProvidersChartData($period),
            'features' => $this->analytics->getFeaturesChartData($period),
            default => [],
        };

        return $this->jsonResponse($chartData);
    }

    private function exportCsv(array $data): ResponseInterface
    {
        $csv = $this->reportGenerator->generateCsv($data);

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="usage-export.csv"')
            ->withBody($this->streamFactory->createStream($csv));
    }
}
```

---

### SettingsController

**Purpose**: Global configuration and system settings

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Backend\Controller;

use Netresearch\NrLlm\Service\ConfigurationService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * System settings controller
 */
class SettingsController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConfigurationService $configService
    ) {}

    /**
     * General settings
     */
    public function generalAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $config = $this->configService->getConfiguration();

        $moduleTemplate->assignMultiple([
            'config' => $config,
            'defaultProviders' => $this->getAvailableProviders(),
        ]);

        return $moduleTemplate->renderResponse('Settings/General');
    }

    /**
     * Quota management settings
     */
    public function quotasAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $quotaConfig = $this->configService->getQuotaConfiguration();

        $moduleTemplate->assignMultiple([
            'quotas' => $quotaConfig,
            'userGroups' => $this->getBackendUserGroups(),
        ]);

        return $moduleTemplate->renderResponse('Settings/Quotas');
    }

    /**
     * Cache settings
     */
    public function cacheAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $cacheConfig = $this->configService->getCacheConfiguration();
        $cacheStats = $this->getCacheStatistics();

        $moduleTemplate->assignMultiple([
            'config' => $cacheConfig,
            'stats' => $cacheStats,
        ]);

        return $moduleTemplate->renderResponse('Settings/Cache');
    }

    /**
     * Security settings
     */
    public function securityAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $securityConfig = $this->configService->getSecurityConfiguration();

        $moduleTemplate->assignMultiple([
            'config' => $securityConfig,
            'encryptionStatus' => $this->checkEncryptionStatus(),
        ]);

        return $moduleTemplate->renderResponse('Settings/Security');
    }

    /**
     * Save settings
     */
    public function updateAction(): ResponseInterface
    {
        $section = $this->request->getArgument('section');
        $settings = $this->request->getArgument('settings');

        try {
            $this->configService->updateConfiguration($section, $settings);

            $this->addFlashMessage(
                'Settings saved successfully',
                'Success',
                \TYPO3\CMS\Core\Messaging\FlashMessage::OK
            );
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                'Error saving settings: ' . $e->getMessage(),
                'Error',
                \TYPO3\CMS\Core\Messaging\FlashMessage::ERROR
            );
        }

        return $this->redirect($section);
    }
}
```

---

## 4. Fluid Template Structure

### Resources/Private/Templates/Dashboard/Index.html

```html
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      xmlns:core="http://typo3.org/ns/TYPO3/CMS/Core/ViewHelpers"
      data-namespace-typo3-fluid="true">

<f:layout name="Module"/>

<f:section name="Content">
    <div class="module-body">
        <h1>LLM Management Dashboard</h1>

        <!-- Quick Stats -->
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5>Today's Requests</h5>
                        <h2>{todayStats.totalRequests}</h2>
                        <small class="text-muted">
                            <f:if condition="{todayStats.changePercent > 0}">
                                <span class="text-success">
                                    ↑ {todayStats.changePercent}%
                                </span>
                            </f:if>
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5>Cache Hit Ratio</h5>
                        <h2>{cacheMetrics.hitRatio}%</h2>
                        <div class="progress">
                            <div class="progress-bar" role="progressbar"
                                 style="width: {cacheMetrics.hitRatio}%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5>Cost This Month</h5>
                        <h2>${costSummary.monthTotal}</h2>
                        <small class="text-muted">
                            Budget: ${costSummary.budget}
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5>Provider Health</h5>
                        <f:for each="{providerHealth}" as="health" key="provider">
                            <span class="badge badge-{health.status}">
                                {provider}: {health.status}
                            </span>
                        </f:for>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row mt-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>Request Timeline</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="requestsChart" height="300"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Provider Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="providersChart" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Users -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Top Users</h5>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Requests</th>
                                    <th>Tokens</th>
                                    <th>Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <f:for each="{topUsers}" as="user">
                                    <tr>
                                        <td>{user.username}</td>
                                        <td>{user.requests}</td>
                                        <td>{user.tokens -> f:format.number()}</td>
                                        <td>${user.cost}</td>
                                    </tr>
                                </f:for>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</f:section>

</html>
```

---

## 5. Dashboard Widgets

### Widget Specifications

#### Total Requests Widget
```yaml
identifier: llm-requests-today
title: "LLM Requests Today"
description: "Total API requests made today"
icon: "actions-document-synchronize"
data_source: AnalyticsService::getTodayStats
refresh_interval: 300  # 5 minutes
```

#### Cost Widget
```yaml
identifier: llm-cost-period
title: "Cost This Period"
description: "API usage cost for selected period"
icon: "actions-wallet"
data_source: AnalyticsService::getCostSummary
configurable: true
options:
  - period: [today, week, month]
```

#### Cache Ratio Widget
```yaml
identifier: llm-cache-ratio
title: "Cache Hit Ratio"
description: "Percentage of requests served from cache"
icon: "actions-database"
data_source: AnalyticsService::getCacheMetrics
visualization: progress_bar
```

#### Provider Status Widget
```yaml
identifier: llm-provider-status
title: "Provider Availability"
description: "Real-time provider health status"
icon: "actions-heartbeat"
data_source: ProviderHealthService::getHealthStatus
refresh_interval: 60
visualization: status_badges
```

---

## 6. JavaScript/TypeScript Components

### Resources/Public/JavaScript/DashboardCharts.js

```javascript
/**
 * Dashboard chart rendering
 */
class DashboardCharts {
    constructor() {
        this.charts = {};
        this.init();
    }

    init() {
        this.renderRequestsChart();
        this.renderProvidersChart();
        this.renderCostChart();
        this.setupAutoRefresh();
    }

    async renderRequestsChart() {
        const data = await this.fetchChartData('requests');
        const ctx = document.getElementById('requestsChart');

        this.charts.requests = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Requests',
                    data: data.values,
                    borderColor: '#0078c8',
                    backgroundColor: 'rgba(0, 120, 200, 0.1)',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }

    async renderProvidersChart() {
        const data = await this.fetchChartData('providers');
        const ctx = document.getElementById('providersChart');

        this.charts.providers = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: [
                        '#0078c8', '#ff8700', '#00c851',
                        '#ffbb33', '#ff4444', '#33b5e5'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    async fetchChartData(type) {
        const response = await fetch(
            `${TYPO3.settings.ajaxUrls['llm_chart_data']}?chart=${type}`
        );
        return response.json();
    }

    setupAutoRefresh() {
        setInterval(() => {
            this.refreshAllCharts();
        }, 60000); // Refresh every minute
    }

    refreshAllCharts() {
        Object.keys(this.charts).forEach(async (key) => {
            const data = await this.fetchChartData(key);
            this.updateChart(this.charts[key], data);
        });
    }

    updateChart(chart, newData) {
        chart.data.labels = newData.labels;
        chart.data.datasets[0].data = newData.values;
        chart.update('none'); // Update without animation
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    new DashboardCharts();
});
```

---

## 7. Permission Management

### Access Control

```php
<?php
// Configuration/TCA/Overrides/be_groups.php

// Add custom permissions for LLM module
$GLOBALS['TCA']['be_groups']['columns']['tx_nrllm_permissions'] = [
    'label' => 'LLM Module Permissions',
    'config' => [
        'type' => 'check',
        'items' => [
            ['View Dashboard', 'dashboard'],
            ['Manage Providers', 'providers'],
            ['Edit Prompts', 'prompts'],
            ['View Usage Reports', 'usage'],
            ['Configure Settings', 'settings'],
            ['Export Data', 'export'],
        ],
    ],
];
```

---

## Summary

### Delivered Components

#### Controllers (5)
- ✅ DashboardController: Overview, stats, quick actions
- ✅ ProvidersController: Provider CRUD, health checks
- ✅ PromptsController: Template management
- ✅ UsageController: Reports, analytics, export
- ✅ SettingsController: Configuration management

#### Templates
- ✅ Dashboard layouts with widgets
- ✅ Provider management forms
- ✅ Prompt editor interface
- ✅ Usage reports and charts
- ✅ Settings panels

#### Features
- Real-time provider health monitoring
- Interactive charts (Chart.js integration)
- CSV/JSON/Excel export
- Template preview and validation
- AJAX-powered UI updates
- Permission-based access control

#### Integration Points
- PSR-14 event system
- TYPO3 backend template system
- Flash messaging
- Module routing
- CSRF protection
