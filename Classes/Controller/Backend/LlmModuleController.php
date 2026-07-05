<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\Analytics\AnalyticsPeriod;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Overview\OverviewReadinessService;
use Netresearch\NrLlm\Service\Overview\ProviderReachabilityService;
use Netresearch\NrLlm\Service\TestPromptResolverInterface;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
final class LlmModuleController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly ProviderRepository $providerRepository,
        private readonly ModelRepository $modelRepository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly TaskRepository $taskRepository,
        private readonly PromptSnippetRepository $promptSnippetRepository,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly FormEngineUrlBuilder $formEngineUrlBuilder,
        private readonly TestPromptResolverInterface $testPromptResolver,
        private readonly OverviewReadinessService $readinessService,
        private readonly ProviderReachabilityService $reachabilityService,
        private readonly UsageAnalyticsServiceInterface $analytics,
        private readonly PageRenderer $pageRenderer,
        private readonly LoggerInterface $logger,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        // Add module menu dropdown to docheader (shows all LLM sub-modules)
        $moduleTemplate->makeDocHeaderModuleMenu();
        $this->buildDocHeaderTabMenu($moduleTemplate, 'dashboard');

        if (method_exists($moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm',
                displayName: 'LLM - Dashboard',
            );
        }

        // Overview-specific styling and the async (token-free) reachability probe.
        $this->pageRenderer->addCssFile('EXT:nr_llm/Resources/Public/Css/Backend/Overview.css');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/OverviewReachability.js');

        $providers = $this->llmServiceManager->getProviderList();
        $availableProviders = $this->llmServiceManager->getAvailableProviders();

        $providerDetails = [];
        foreach ($providers as $identifier => $name) {
            $isAvailable = isset($availableProviders[$identifier]);
            $provider = $isAvailable ? $availableProviders[$identifier] : null;

            $providerDetails[$identifier] = [
                'name' => $name,
                'identifier' => $identifier,
                'available' => $isAvailable,
                'models' => $provider?->getAvailableModels() ?? [],
                'defaultModel' => $provider?->getDefaultModel() ?? '',
                'features' => $this->getProviderFeatures($provider),
            ];
        }

        // Get counts from database repositories (non-deleted records only)
        $dbProviderCount = $this->providerRepository->countActive();
        $dbModelCount = $this->modelRepository->countActive();
        $dbConfigurationCount = $this->configurationRepository->countActive();
        $dbTaskCount = $this->taskRepository->countActive();
        $dbSnippetCount = $this->promptSnippetRepository->countActive();

        // Per-module setup state, folded onto the cards (green / next / empty / locked).
        $statuses = $this->readinessService->buildStatuses();

        // Analytics band: 30-day KPI totals + 7-day per-provider request mix.
        $now = new DateTimeImmutable();
        $kpiPeriod = AnalyticsPeriod::fromPreset('30d', $now);
        $providerPeriod = AnalyticsPeriod::fromPreset('7d', $now);
        $kpi = $this->analytics->getKpiTotals($kpiPeriod->from, $kpiPeriod->to);
        $providerBreakdown = $this->analytics->getBreakdownByProvider($providerPeriod->from, $providerPeriod->to);
        // Rank by request volume so the "Requests by provider" bars read as a
        // descending top-list, then keep the top three.
        usort($providerBreakdown, static fn(array $a, array $b): int => $b['requests'] <=> $a['requests']);
        $providerBreakdown = array_slice($providerBreakdown, 0, 3);
        $maxProviderRequests = 0;
        foreach ($providerBreakdown as $row) {
            $maxProviderRequests = max($maxProviderRequests, $row['requests']);
        }

        // Configured provider records drive the reachability dots (keyed by the
        // record identifier the AJAX probe reports on); the async JS fills them.
        $configuredProviders = [];
        foreach ($this->providerRepository->findActive() as $providerRecord) {
            $configuredProviders[] = [
                'identifier' => $providerRecord->getIdentifier(),
                'name'       => $providerRecord->getName(),
            ];
        }

        $moduleTemplate->assignMultiple([
            'providers' => $providerDetails,
            'configuredProviders' => $configuredProviders,
            'dbProviderCount' => $dbProviderCount,
            'dbModelCount' => $dbModelCount,
            'dbConfigurationCount' => $dbConfigurationCount,
            'dbTaskCount' => $dbTaskCount,
            'dbSnippetCount' => $dbSnippetCount,
            'statuses' => $statuses,
            'kpi' => $kpi,
            'hasUsage' => $kpi['requests'] > 0,
            'providerBreakdown' => $providerBreakdown,
            'maxProviderRequests' => $maxProviderRequests,
            'analyticsUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_analytics'),
            'taskWizardUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_tasks', [
                'controller' => 'Backend\\TaskWizard',
                'action'     => 'wizardForm',
            ]),
            // FormEngine new-record URLs for the "+ New …" card actions
            'newProviderUrl' => $this->formEngineUrlBuilder->buildNewUrl('tx_nrllm_provider', 'nrllm_overview'),
            'newModelUrl' => $this->formEngineUrlBuilder->buildNewUrl('tx_nrllm_model', 'nrllm_overview'),
            'newConfigurationUrl' => $this->formEngineUrlBuilder->buildNewUrl('tx_nrllm_configuration', 'nrllm_overview'),
            'newTaskUrl' => $this->formEngineUrlBuilder->buildNewUrl('tx_nrllm_task', 'nrllm_overview'),
            'newSnippetUrl' => $this->formEngineUrlBuilder->buildNewUrl('tx_nrllm_promptsnippet', 'nrllm_overview'),
        ]);

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    /**
     * AJAX: token-free reachability of the vault and configured providers.
     *
     * Admin-only (the module is admin-only, but AJAX routes bypass the module
     * access check — see {@see RequiresBackendAdminTrait}). Performs no LLM
     * inference, so it consumes no tokens.
     */
    public function reachabilityAction(): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }

        return new JsonResponse($this->reachabilityService->check());
    }

    public function testAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        // Add module menu dropdown to docheader (shows all LLM sub-modules)
        $moduleTemplate->makeDocHeaderModuleMenu();

        if (method_exists($moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm',
                displayName: 'LLM - Test',
                arguments: ['action' => 'test'],
            );
        }

        $providers = $this->llmServiceManager->getAvailableProviders();

        $moduleTemplate->assignMultiple([
            'providers' => array_map(
                fn($p) => ['identifier' => $p->getIdentifier(), 'name' => $p->getName()],
                $providers,
            ),
        ]);

        return $moduleTemplate->renderResponse('Backend/Test');
    }

    public function executeTestAction(): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $this->request->getParsedBody();
        $provider = $this->extractStringFromBody($body, 'provider');
        $prompt = $this->extractStringFromBody($body, 'prompt', $this->testPromptResolver->resolve());

        if ($provider === '') {
            return new JsonResponse(['error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.config.noProvider', 'No provider specified')], 400);
        }

        try {
            $chatOptions = new ChatOptions(provider: $provider);
            $response = $this->llmServiceManager->complete($prompt, $chatOptions);

            $result = new JsonResponse([
                'success' => true,
                'content' => $response->content,
                'model' => $response->model,
                'usage' => [
                    'promptTokens' => $response->usage->promptTokens,
                    'completionTokens' => $response->usage->completionTokens,
                    'totalTokens' => $response->usage->totalTokens,
                ],
            ]);
        } catch (ProviderException $e) {
            $this->logger->warning('LlmModule test: provider error', ['exception' => $e]);
            $result = new JsonResponse([
                'success' => false,
                'error'   => 'LLM provider error during test. See system log for details.',
            ], 502);
        } catch (Throwable $e) {
            $this->logger->error('LlmModule test: unexpected error', ['exception' => $e]);
            $result = new JsonResponse([
                'success' => false,
                'error'   => 'Test failed. See system log for details.',
            ], 500);
        }

        return $result;
    }

    public function helpAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $this->buildDocHeaderTabMenu($moduleTemplate, 'help');

        $moduleTemplate->assignMultiple([
            'dashboardUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_overview'),
            'wizardUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_wizard'),
        ]);

        return $moduleTemplate->renderResponse('Backend/Help');
    }

    /**
     * Build a Dashboard/Help tab menu in the docheader.
     */
    private function buildDocHeaderTabMenu(ModuleTemplate $moduleTemplate, string $activeTab): void
    {
        $menuRegistry = $moduleTemplate->getDocHeaderComponent()->getMenuRegistry();
        $menu = $menuRegistry->makeMenu();
        $menu->setIdentifier('LlmModuleMenu');

        $dashboardItem = $menu->makeMenuItem()
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:tab.dashboard', 'NrLlm') ?? 'Dashboard')
            ->setHref((string)$this->backendUriBuilder->buildUriFromRoute('nrllm_overview'));
        if ($activeTab === 'dashboard') {
            $dashboardItem->setActive(true);
        }
        $menu->addMenuItem($dashboardItem);

        $helpItem = $menu->makeMenuItem()
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:tab.help', 'NrLlm') ?? 'Help')
            ->setHref($this->uriBuilder->reset()->uriFor('help'));
        if ($activeTab === 'help') {
            $helpItem->setActive(true);
        }
        $menu->addMenuItem($helpItem);

        $menuRegistry->addMenu($menu);
    }

    /**
     * Extract string value from request body.
     */
    private function extractStringFromBody(mixed $body, string $key, string $default = ''): string
    {
        if (!is_array($body)) {
            return $default;
        }

        $value = $body[$key] ?? $default;

        return is_string($value) || is_numeric($value) ? (string)$value : $default;
    }

    /**
     * @return array<string, bool>
     */
    private function getProviderFeatures(?ProviderInterface $provider): array
    {
        if ($provider === null) {
            return [];
        }

        return [
            'chat' => $provider->supportsFeature('chat'),
            'completion' => $provider->supportsFeature('completion'),
            'embeddings' => $provider->supportsFeature('embeddings'),
            'vision' => $provider->supportsFeature('vision'),
            'streaming' => $provider->supportsFeature('streaming'),
            'tools' => $provider->supportsFeature('tools'),
        ];
    }

}
