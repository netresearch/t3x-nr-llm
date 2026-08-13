<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceSimulation;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingReadout;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Analytics\AnalyticsPeriod;
use Netresearch\NrLlm\Service\Governance\EffectivePolicyReadout;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Governance\GovernanceProfileEvaluator;
use Netresearch\NrLlm\Service\Governance\SimulationActorDirectory;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\OperationCapabilityMap;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Overview\OverviewReadinessService;
use Netresearch\NrLlm\Service\Overview\ProviderReachabilityService;
use Netresearch\NrLlm\Service\Telemetry\RoutedCall;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Netresearch\NrLlm\Service\TestPromptResolverInterface;
use Netresearch\NrLlm\Service\Tool\GovernanceSimulator;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class LlmModuleController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    /** How far back the routed-call readout looks (ADR-156). */
    private const ROUTED_CALL_WINDOW_DAYS = 7;

    /** How many routed calls the readout shows, newest first. */
    private const ROUTED_CALL_LIMIT = 20;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly ProviderRepository $providerRepository,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly FormEngineUrlBuilder $formEngineUrlBuilder,
        private readonly TestPromptResolverInterface $testPromptResolver,
        private readonly OverviewReadinessService $readinessService,
        private readonly ProviderReachabilityService $reachabilityService,
        private readonly UsageAnalyticsServiceInterface $analytics,
        private readonly EffectivePolicyReadout $effectivePolicyReadout,
        private readonly GovernanceProfileEvaluator $governanceProfileEvaluator,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly GovernanceSimulator $governanceSimulator,
        private readonly SimulationActorDirectory $simulationActors,
        private readonly ToolRegistry $toolRegistry,
        private readonly ModelSelectionServiceInterface $modelSelectionService,
        // The routed-call reader (ADR-156). Injected directly rather than behind
        // a report service: unlike the fallback rescues, which need a domain
        // rule to classify a hop, "which recent calls recorded a decision" is
        // the repository query itself.
        private readonly TelemetryRepositoryInterface $telemetryRepository,
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
        // Pre-compute the bar width per provider here so the template stays
        // logic-free and can never divide by zero.
        $maxProviderRequests = 0;
        foreach ($providerBreakdown as $row) {
            $maxProviderRequests = max($maxProviderRequests, $row['requests']);
        }

        foreach ($providerBreakdown as &$providerRow) {
            $providerRow['percentage'] = $maxProviderRequests > 0
                ? (int)round($providerRow['requests'] * 100 / $maxProviderRequests)
                : 0;
        }

        unset($providerRow);

        // 30-day daily request history for the sparkline. Bar heights are
        // pre-computed here (percentage of the busiest day) so the template
        // stays logic-free and cannot divide by zero.
        $dailyTrend = $this->analytics->getDailyTrend($kpiPeriod->from, $kpiPeriod->to);
        $maxDailyRequests = 0;
        foreach ($dailyTrend as $day) {
            $maxDailyRequests = max($maxDailyRequests, $day['requests']);
        }

        $dailyBars = [];
        foreach ($dailyTrend as $day) {
            $dailyBars[] = [
                'date'     => $day['date'],
                'requests' => $day['requests'],
                'height'   => $maxDailyRequests > 0 ? max(3, (int)round($day['requests'] * 100 / $maxDailyRequests)) : 0,
            ];
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
            'configuredProviders' => $configuredProviders,
            'statuses' => $statuses,
            'kpi' => $kpi,
            'hasUsage' => $kpi['requests'] > 0,
            'providerBreakdown' => $providerBreakdown,
            'dailyBars' => $dailyBars,
            'analyticsUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_analytics', [
                'controller' => 'Backend\\Analytics',
                'action'     => 'index',
            ]),
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
     * AJAX: token-free reachability of the configured providers.
     *
     * Admin-only (the module is admin-only, but AJAX routes bypass the module
     * access check — see {@see RequiresBackendAdminTrait}). Performs no LLM
     * inference, so it consumes no tokens.
     */
    public function reachabilityAction(): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
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

        // Wire the test form to the nrllm_test AJAX endpoint. Test.js carries
        // top-level ES imports, so it must load through the import map rather
        // than as a classic <script> (which would throw "Cannot use import
        // statement outside a module").
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/Test.js');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/SpecializedTest.js');

        $providers = $this->llmServiceManager->getAvailableProviders();

        $moduleTemplate->assignMultiple([
            'providers' => array_map(
                fn(ProviderInterface $p): array => ['identifier' => $p->getIdentifier(), 'name' => $p->getName()],
                $providers,
            ),
        ]);

        return $moduleTemplate->renderResponse('Backend/Test');
    }

    public function executeTestAction(): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
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

    /**
     * Read-only effective-policy readout (ADR-140).
     *
     * Shows the four governance keys that carry a decision, each read through
     * the same resolver the runtime uses. There is deliberately no apply path
     * and no provenance column — see ADR-140. Instance-wide keys are set in the
     * Install Tool.
     */
    public function governanceAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $this->buildDocHeaderTabMenu($moduleTemplate, 'governance');

        if (method_exists($moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm',
                displayName: 'LLM - Governance',
                arguments: ['action' => 'governance'],
            );
        }

        // The profile is a lens on the readout, not a second source: it is
        // chosen in the URL, compared against the same rows the table shows,
        // and applied to nothing (ADR-145). None selected means no comparison.
        $profile = GovernanceProfile::fromValue($this->profileArgument());

        $policyRows = $this->effectivePolicyReadout->rows();
        $simulation = $this->simulate();
        $routing    = $this->explainRouting();

        $moduleTemplate->assignMultiple([
            // The routing readout (ADR-148). The candidate tables are flattened
            // here for the same reason indexAction pre-computes its bar widths:
            // a signal of null means "no data" and a score is a formatted
            // number, and neither distinction survives a Fluid conditional.
            'routing'               => $routing,
            'routingEligible'       => $this->candidateRows($routing, true),
            'routingRejected'       => $this->candidateRows($routing, false),
            'routingOperations'     => $this->constrainingOperations(),
            'routingModes'          => RoutingPolicyMode::cases(),
            'routingConfiguration'  => $this->queryParam('routeConfiguration'),
            'routingOperation'      => $this->queryParam('routeOperation'),
            'routingPolicyMode'     => $this->queryParam('routePolicyMode'),
            // The other half of the same question (ADR-156). The readout above
            // answers "which model WOULD serve this"; this answers "which model
            // DID, and why" for calls that already ran. It needs no form: it is
            // not a query an operator composes, it is what happened.
            'routedCalls'           => $this->recentRoutedCalls(),
            'routedCallsDays'       => self::ROUTED_CALL_WINDOW_DAYS,
            // Both forms GET to the same action, so each has to carry the
            // other's state or submitting one wipes the other's answer off the
            // page — the tab holds two questions about the same configuration
            // and reading them together is the point.
            'simulateTool'          => $this->queryParam('simulateTool'),
            'simulateConfiguration' => $this->queryParam('simulateConfiguration'),
            'simulateActor'         => $this->queryParam('simulateActor'),
            'policyRows'     => $policyRows,
            'profiles'       => GovernanceProfile::cases(),
            'profile'        => $profile,
            'deviations'     => $profile instanceof GovernanceProfile ? $this->governanceProfileEvaluator->deviations($policyRows, $profile) : [],
            'configurations' => $this->configurationRepository->findActive(),
            'toolNames'      => $this->toolRegistry->names(),
            // Backend users the simulation can answer for (ADR-157). A read of
            // be_users, not an impersonation surface — see
            // SimulationActorDirectory.
            'simulationActors' => $this->simulationActors->actors(),
            'simulation'       => $simulation,
            // message() does not follow the get/is/has convention Fluid needs,
            // so the strings are assigned rather than reached through the
            // objects.
            'simulationToolMessage'    => $simulation?->tool->message(),
            'simulationContextMessage' => $simulation?->context->message(),
        ]);

        return $moduleTemplate->renderResponse('Backend/Governance');
    }

    /**
     * The profile named in the request, as an unvalidated string.
     *
     * Validation is {@see GovernanceProfile::fromValue()}'s job, which returns
     * null for anything it does not recognise — so a hand-edited URL selects no
     * profile rather than producing a comparison against something invented.
     */
    private function profileArgument(): ?string
    {
        $profile = $this->request->getQueryParams()['profile'] ?? null;

        return is_string($profile) ? $profile : null;
    }

    /**
     * Answer "would this run be allowed" through the REAL gates (ADR-145,
     * ADR-157).
     *
     * {@see GovernanceSimulator} calls the five runtime services in turn — the
     * tool gate, the input-context gate, the routing decision, the approval
     * predicate and configuration access (ADR-167) — and folds their answers
     * into one verdict. None of them is
     * reimplemented here: a simulator with its own copy of a policy is worse
     * than none, because the two can disagree and only one of them runs.
     *
     * This method parses three query parameters and hands them over. The actor
     * is a uid, resolved read-only inside the service through
     * {@see \Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface};
     * absent or 0, the answer is for the operator reading the page, which is
     * what ADR-145 shipped.
     *
     * Returns null when the request names no pair to simulate.
     */
    private function simulate(): ?GovernanceSimulation
    {
        $toolName      = $this->queryParam('simulateTool');
        $configuration = $this->queryParam('simulateConfiguration');

        if ($toolName === '' || $configuration === '') {
            return null;
        }

        $entity = $this->configurationRepository->findOneByIdentifier($configuration);
        if (!$entity instanceof LlmConfiguration) {
            return null;
        }

        $operator = $GLOBALS['BE_USER'] ?? null;

        return $this->governanceSimulator->simulate(
            $toolName,
            $entity,
            (int)$this->queryParam('simulateActor'),
            $operator instanceof BackendUserAuthentication ? $operator : null,
        );
    }

    /**
     * Answer "why this model and not that one" through the REAL decision point
     * (ADR-148).
     *
     * {@see ModelSelectionServiceInterface::explainRouting()} is the same
     * resolution the runtime performs — the fixed-vs-criteria branch, the
     * operation-capability switch and the ranking all come from the service
     * that owns them. This method parses three query parameters and renders
     * what it gets back; it decides nothing.
     *
     * Returns null when the request names no configuration to explain.
     */
    private function explainRouting(): ?RoutingReadout
    {
        $identifier = $this->queryParam('routeConfiguration');
        if ($identifier === '') {
            return null;
        }

        $configuration = $this->configurationRepository->findOneByIdentifier($identifier);
        if (!$configuration instanceof LlmConfiguration) {
            return null;
        }

        return $this->modelSelectionService->explainRouting(
            $configuration,
            ProviderOperation::tryFrom($this->queryParam('routeOperation')),
            // tryFrom(), NOT RoutingPolicyMode::fromValue(): an unrecognised
            // value here must mean "no override" so the page answers for what
            // actually runs. fromValue() answers a different question — it
            // defaults a broken SETTING to the established ordering — and
            // reusing it would turn a typo in the URL into a mode the operator
            // never selected.
            RoutingPolicyMode::tryFrom(trim($this->queryParam('routePolicyMode'))),
        );
    }

    /**
     * The operations worth offering: the ones that actually constrain a
     * criteria-mode decision.
     *
     * Filtered through {@see OperationCapabilityMap} rather than listed, so the
     * selector follows the map instead of drifting from it. An operation the
     * map answers null for adds no constraint, and offering it would promise a
     * dimension the decision does not have.
     *
     * @return list<ProviderOperation>
     */
    private function constrainingOperations(): array
    {
        return array_values(array_filter(
            ProviderOperation::cases(),
            static fn(ProviderOperation $operation): bool => OperationCapabilityMap::capabilityFor($operation) instanceof ModelCapability,
        ));
    }

    /**
     * One display row per candidate, eligible or rejected.
     *
     * @return list<array{modelId: string, name: string, provider: string, score: string, reasonKey: string, signals: list<array{name: string, value: string, known: bool}>}>
     */
    private function candidateRows(?RoutingReadout $readout, bool $eligible): array
    {
        $decision = $readout?->decision;
        if (!$decision instanceof RoutingDecision) {
            return [];
        }

        $rows = [];
        foreach ($eligible ? $decision->eligibleCandidates() : $decision->rejectedCandidates() as $candidate) {
            $rows[] = [
                'modelId'   => $candidate->modelId(),
                'name'      => $candidate->model->getName(),
                'provider'  => $candidate->providerIdentifier(),
                'score'     => $candidate->score === null ? '' : number_format($candidate->score, 3),
                'reasonKey' => $candidate->rejectionReason?->getLabelKey() ?? '',
                'signals'   => $this->signalRows($candidate),
            ];
        }

        return $rows;
    }

    /**
     * The per-signal values behind one candidate's score.
     *
     * `known` is carried separately because a signal with no data is null and a
     * measured zero is 0.0 — in Fluid both are falsy, and collapsing them would
     * report "this model scored nothing" where the truth is "nobody measured
     * it".
     *
     * @return list<array{name: string, value: string, known: bool}>
     */
    private function signalRows(RoutingCandidate $candidate): array
    {
        $rows = [];
        foreach ($candidate->signals as $name => $value) {
            $rows[] = [
                'name'  => $name,
                'value' => $value === null ? '' : number_format($value, 2),
                'known' => $value !== null,
            ];
        }

        return $rows;
    }

    /**
     * The recent calls whose model was chosen automatically (ADR-156).
     *
     * The reader ADR-142 made the condition of persisting the decision at all.
     * Fixed window and fixed cap rather than a form: the page already carries
     * two forms, and a third control for "how far back" would ask an operator
     * to tune a query before they have seen an answer. A week of decisions at
     * twenty rows is enough to see whether the modes correspond to anything —
     * which is the question ADR-142's "revisit when" names.
     *
     * Fail-soft: the routed-call table is an observation, and a read error must
     * not take the whole Governance tab down with it.
     *
     * @return list<RoutedCall>
     */
    private function recentRoutedCalls(): array
    {
        try {
            return $this->telemetryRepository->recentRoutedCalls(
                time() - (self::ROUTED_CALL_WINDOW_DAYS * 86400),
                self::ROUTED_CALL_LIMIT,
            );
        } catch (Throwable $e) {
            $this->logger->warning('Failed to read recent routed calls for the Governance tab', ['exception' => $e]);

            return [];
        }
    }

    /**
     * A query parameter as a string, empty when absent or not a string.
     */
    private function queryParam(string $name): string
    {
        $value = $this->request->getQueryParams()[$name] ?? null;

        return is_string($value) ? $value : '';
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
     * Build a Dashboard/Governance/Help tab menu in the docheader.
     *
     * These are links to real routes, not in-page tabs: the dashboard entry
     * goes through the backend route, the other two through Extbase actions of
     * this controller.
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

        $governanceItem = $menu->makeMenuItem()
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:tab.governance', 'NrLlm') ?? 'Governance')
            ->setHref($this->uriBuilder->reset()->uriFor('governance'));
        if ($activeTab === 'governance') {
            $governanceItem->setActive(true);
        }

        $menu->addMenuItem($governanceItem);

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

}
