<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use JsonException;
use Netresearch\NrLlm\Controller\Backend\Response\DiscoveredModelsResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\GeneratedConfigurationsResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ProviderDetectionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\WizardSaveResponse;
use Netresearch\NrLlm\Controller\Backend\Response\WizardTestConnectionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\SetupWizard\ConfigurationGenerator;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Netresearch\NrLlm\Service\SetupWizard\ProviderDetector;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Backend controller for LLM Setup Wizard.
 *
 * Provides a guided setup experience for configuring LLM providers,
 * models, and configurations with auto-detection and LLM-assisted
 * configuration generation.
 */
#[AsController]
final class SetupWizardController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    private const ERROR_ENDPOINT_REQUIRED = 'Endpoint URL is required';

    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ProviderDetector $providerDetector,
        private readonly ModelDiscoveryInterface $modelDiscovery,
        private readonly ConfigurationGenerator $configurationGenerator,
        private readonly ProviderRepository $providerRepository,
        private readonly ModelRepository $modelRepository,
        private readonly LlmConfigurationRepository $llmConfigurationRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly IconFactory $iconFactory,
        private readonly VaultServiceInterface $vaultService,
        private readonly LoggerInterface $logger,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $this->moduleTemplate->makeDocHeaderModuleMenu();

        // Register AJAX URLs for wizard JavaScript
        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_wizard_detect' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_wizard_detect'),
            'nrllm_wizard_test' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_wizard_test'),
            'nrllm_wizard_discover' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_wizard_discover'),
            'nrllm_wizard_generate' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_wizard_generate'),
            'nrllm_wizard_save' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_wizard_save'),
        ]);

        // Register module URLs for navigation
        $this->pageRenderer->addInlineSettingArray('moduleUrls', [
            'nrllm_providers' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_providers'),
        ]);

        // Load wizard CSS and JavaScript (ES6 module)
        $this->pageRenderer->addCssFile('EXT:nr_llm/Resources/Public/Css/Backend/SetupWizard.css');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/SetupWizard.js');
    }

    /**
     * Display the setup wizard.
     */
    public function indexAction(): ResponseInterface
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();

        // Add refresh button
        $refreshButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL))
            ->setTitle($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.refresh', 'Refresh'))
            ->setShowLabelText(true)
            ->setHref((string)$this->backendUriBuilder->buildUriFromRoute('nrllm_wizard'));
        $buttonBar->addButton($refreshButton);

        if (method_exists($this->moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm_wizard',
                displayName: 'LLM - Setup Wizard',
            );
        }

        // Provide adapter types for the form
        $this->moduleTemplate->assignMultiple([
            'adapterTypes' => $this->providerDetector->getSupportedAdapterTypes(),
        ]);

        return $this->moduleTemplate->renderResponse('Backend/SetupWizard/Index');
    }

    /**
     * AJAX: Detect provider from endpoint URL.
     */
    public function detectAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $endpoint = $this->extractStringFromBody($body, 'endpoint');

        if ($endpoint === '') {
            return new JsonResponse(
                (new ErrorResponse(self::ERROR_ENDPOINT_REQUIRED))->jsonSerialize(),
                400,
            );
        }

        $detected = $this->providerDetector->detect($endpoint);

        return new JsonResponse(
            ProviderDetectionResponse::fromDetectedProvider($detected)->jsonSerialize(),
        );
    }

    /**
     * AJAX: Test connection to provider.
     */
    public function testAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $endpoint = $this->extractStringFromBody($body, 'endpoint');
        $apiKey = $this->extractStringFromBody($body, 'apiKey');
        $adapterType = $this->extractStringFromBody($body, 'adapterType', 'openai');

        if ($endpoint === '') {
            return new JsonResponse(
                (new ErrorResponse(self::ERROR_ENDPOINT_REQUIRED))->jsonSerialize(),
                400,
            );
        }

        $detected = new DetectedProvider(
            adapterType: $adapterType,
            suggestedName: 'Test Provider',
            endpoint: $endpoint,
        );

        $result = $this->modelDiscovery->testConnection($detected, $apiKey);

        return new JsonResponse(
            WizardTestConnectionResponse::fromResult($result)->jsonSerialize(),
        );
    }

    /**
     * AJAX: Discover available models from provider.
     */
    public function discoverAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $endpoint = $this->extractStringFromBody($body, 'endpoint');
        $apiKey = $this->extractStringFromBody($body, 'apiKey');
        $adapterType = $this->extractStringFromBody($body, 'adapterType', 'openai');

        if ($endpoint === '') {
            return new JsonResponse(
                (new ErrorResponse(self::ERROR_ENDPOINT_REQUIRED))->jsonSerialize(),
                400,
            );
        }

        $detected = new DetectedProvider(
            adapterType: $adapterType,
            suggestedName: 'Provider',
            endpoint: $endpoint,
        );

        // `fromDiscoveredModels()` reindexes via `array_values(array_map(...))`
        // internally, so passing the raw discover() result here is fine.
        return new JsonResponse(
            DiscoveredModelsResponse::fromDiscoveredModels(
                $this->modelDiscovery->discover($detected, $apiKey),
            )->jsonSerialize(),
        );
    }

    /**
     * AJAX: Generate configuration suggestions using the LLM.
     */
    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        try {
            $body = $this->parseRequestBody($request);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 400);
        }
        $endpoint = $this->extractStringFromBody($body, 'endpoint');
        $apiKey = $this->extractStringFromBody($body, 'apiKey');
        $adapterType = $this->extractStringFromBody($body, 'adapterType', 'openai');
        $modelsData = $this->extractArrayFromBody($body, 'models');

        if ($endpoint === '' || $modelsData === []) {
            return new JsonResponse(
                (new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.wizard.endpointModelsRequired', 'Endpoint and models are required')))->jsonSerialize(),
                400,
            );
        }

        $detected = new DetectedProvider(
            adapterType: $adapterType,
            suggestedName: 'Provider',
            endpoint: $endpoint,
        );

        // Convert model data to DTOs
        $models = $this->buildDiscoveredModels($modelsData);

        // `fromSuggestedConfigurations()` reindexes via
        // `array_values(array_map(...))` internally — the redundant
        // `array_values()` wrapper here was a leftover from the
        // pre-DTO inline-array shape.
        return new JsonResponse(
            GeneratedConfigurationsResponse::fromSuggestedConfigurations(
                $this->configurationGenerator->generate($detected, $apiKey, $models),
            )->jsonSerialize(),
        );
    }

    /**
     * AJAX: Save wizard results to database.
     */
    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        try {
            $body = $this->parseRequestBody($request);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 400);
        }
        $providerData = $this->extractArrayFromBody($body, 'provider');
        $modelsData = $this->extractArrayFromBody($body, 'models');
        $configurationsData = $this->extractArrayFromBody($body, 'configurations');
        $pid = $this->extractIntFromBody($body, 'pid');

        if ($providerData === [] || $modelsData === []) {
            return new JsonResponse(
                (new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.wizard.providerModelsRequired', 'Provider and models are required')))->jsonSerialize(),
                400,
            );
        }

        try {
            return $this->persistWizardResult($providerData, $modelsData, $configurationsData, $pid);
        } catch (Throwable $e) {
            $this->logger->error('Setup wizard: failed to persist wizard result', ['exception' => $e]);
            return new JsonResponse(
                (new ErrorResponse('Failed to save. See the system log for details.'))->jsonSerialize(),
                500,
            );
        }
    }

    /**
     * Persist the provider, models and configurations gathered by the wizard.
     *
     * @param array<mixed> $providerData
     * @param array<mixed> $modelsData
     * @param array<mixed> $configurationsData
     */
    private function persistWizardResult(array $providerData, array $modelsData, array $configurationsData, int $pid): ResponseInterface
    {
        // Create provider
        $providerName = is_string($providerData['suggestedName'] ?? null) ? $providerData['suggestedName'] : 'provider';
        $providerAdapter = is_string($providerData['adapterType'] ?? null) ? $providerData['adapterType'] : 'openai';
        $providerEndpoint = is_string($providerData['endpoint'] ?? null) ? $providerData['endpoint'] : '';
        // Store the canonical base URL for the adapter (e.g. append "/v1" for OpenAI) so
        // the saved provider works even if the client sent the raw, un-versioned host.
        if ($providerEndpoint !== '') {
            $providerEndpoint = $this->providerDetector->normalizeEndpointForAdapter($providerEndpoint, $providerAdapter);
        }
        $providerApiKey = is_string($providerData['apiKey'] ?? null) ? $providerData['apiKey'] : '';

        // Store the API key in the vault and use the vault identifier
        $vaultIdentifier = '';
        if ($providerApiKey !== '') {
            try {
                $vaultIdentifier = $this->generateVaultIdentifier();
                $this->vaultService->store($vaultIdentifier, $providerApiKey, [
                    'table' => 'tx_nrllm_provider',
                    'field' => 'api_key',
                    'source' => 'setup_wizard',
                ]);
            } catch (Throwable $e) {
                $this->logger->error('Setup wizard: failed to store API key in vault', ['exception' => $e]);
                return new JsonResponse(
                    (new ErrorResponse('Failed to store the API key securely. See the system log for details.'))->jsonSerialize(),
                    500,
                );
            }
        }

        $provider = new Provider();
        $provider->setIdentifier($this->generateIdentifier($providerName));
        $provider->setName($this->truncateLabel($providerName !== '' ? $providerName : 'New Provider'));
        $provider->setAdapterType($providerAdapter);
        $provider->setEndpointUrl($providerEndpoint);
        $provider->setApiKey($vaultIdentifier);
        $provider->setIsActive(true);
        if ($pid >= 0) {
            $provider->setPid($pid);
        }

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();

        $savedModels = $this->createModels($modelsData, $provider, $pid);
        $this->persistenceManager->persistAll();

        $this->createConfigurations($configurationsData, $savedModels, $pid);
        $this->persistenceManager->persistAll();

        return new JsonResponse(
            WizardSaveResponse::fromProvider(
                provider: $provider,
                modelsCount: count($savedModels),
                configurationsCount: $this->countSelected($configurationsData),
            )->jsonSerialize(),
        );
    }

    /**
     * Create and register the selected models for the given provider.
     *
     * @param array<mixed> $modelsData
     *
     * @return array<string, Model>
     */
    private function createModels(array $modelsData, Provider $provider, int $pid): array
    {
        /** @var array<string, Model> $savedModels */
        $savedModels = [];

        // Clear existing defaults before setting new ones
        if ($this->hasNewDefaultModel($modelsData)) {
            $this->modelRepository->unsetAllDefaults();
            $this->persistenceManager->persistAll();
        }

        // Create models
        foreach ($modelsData as $modelData) {
            if (!is_array($modelData)) {
                continue;
            }
            if (!($modelData['selected'] ?? false)) {
                continue;
            }

            $modelName = is_string($modelData['name'] ?? null) ? $modelData['name'] : 'model';
            $modelId = is_string($modelData['modelId'] ?? null) ? $modelData['modelId'] : '';
            $modelDescription = is_string($modelData['description'] ?? null) ? $modelData['description'] : '';
            // Keep only string capabilities so a malformed payload (nested or
            // non-string entries) can never reach the model as a bad list.
            $modelCapabilities = array_values(array_filter(
                is_array($modelData['capabilities'] ?? null) ? $modelData['capabilities'] : ['chat'],
                is_string(...),
            ));
            $contextLength = is_numeric($modelData['contextLength'] ?? null) ? (int)$modelData['contextLength'] : 0;
            $maxOutputTokens = is_numeric($modelData['maxOutputTokens'] ?? null) ? (int)$modelData['maxOutputTokens'] : 0;

            $model = new Model();
            $model->setIdentifier($this->generateIdentifier($modelName));
            $model->setName($this->truncateLabel($modelName));
            $model->setDescription($modelDescription);
            $model->setModelId($modelId);
            $model->setProvider($provider);
            $model->setContextLength($contextLength);
            $model->setMaxOutputTokens($maxOutputTokens);
            $model->setCapabilities(implode(',', array_filter($modelCapabilities, is_string(...))));
            $model->setIsActive(true);
            $model->setIsDefault((bool)($modelData['recommended'] ?? false));
            if ($pid >= 0) {
                $model->setPid($pid);
            }

            $this->modelRepository->add($model);
            if ($modelId !== '') {
                $savedModels[$modelId] = $model;
            }
        }

        return $savedModels;
    }

    /**
     * Check if any selected model is flagged as the recommended default.
     *
     * @param array<mixed> $modelsData
     */
    private function hasNewDefaultModel(array $modelsData): bool
    {
        foreach ($modelsData as $modelData) {
            if (is_array($modelData) && ($modelData['selected'] ?? false) && ($modelData['recommended'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create and register the selected configurations.
     *
     * @param array<mixed>         $configurationsData
     * @param array<string, Model> $savedModels
     */
    private function createConfigurations(array $configurationsData, array $savedModels, int $pid): void
    {
        // Create configurations
        foreach ($configurationsData as $configData) {
            if (!is_array($configData)) {
                continue;
            }
            if (!($configData['selected'] ?? false)) {
                continue;
            }

            $recommendedModelId = is_string($configData['recommendedModelId'] ?? null) ? $configData['recommendedModelId'] : '';
            // Prefer the recommended model; otherwise fall back to the first
            // saved model, or null when none were saved.
            $model = $savedModels[$recommendedModelId] ?? (empty($savedModels) ? null : reset($savedModels));

            if ($model === null) {
                continue;
            }

            $configName = is_string($configData['name'] ?? null) ? $configData['name'] : 'config';
            $configIdentifier = is_string($configData['identifier'] ?? null) ? $configData['identifier'] : '';
            $configDescription = is_string($configData['description'] ?? null) ? $configData['description'] : '';
            $systemPrompt = is_string($configData['systemPrompt'] ?? null) ? $configData['systemPrompt'] : '';
            // Clamp to valid ranges (mirrors TaskWizardController): temperature
            // 0.0–2.0, maxTokens 1–128000, so the wizard cannot persist
            // out-of-range values that the providers would reject.
            $temperature = max(0.0, min(2.0, is_numeric($configData['temperature'] ?? null) ? (float)$configData['temperature'] : 0.7));
            $maxTokens = max(1, min(128000, is_numeric($configData['maxTokens'] ?? null) ? (int)$configData['maxTokens'] : 4096));

            $config = new LlmConfiguration();
            // Identifier columns are varchar(100), name columns varchar(255).
            $config->setIdentifier($configIdentifier !== '' ? $this->normalizeIdentifier($configIdentifier) : $this->generateIdentifier($configName));
            $config->setName($this->truncateLabel($configName));
            $config->setDescription($configDescription);
            $config->setSystemPrompt($systemPrompt);
            $config->setLlmModel($model);
            $config->setTemperature($temperature);
            $config->setMaxTokens($maxTokens);
            $config->setIsActive(true);
            if ($pid >= 0) {
                $config->setPid($pid);
            }

            $this->llmConfigurationRepository->add($config);
        }
    }

    /**
     * Count how many entries are flagged as selected.
     *
     * @param array<mixed> $items
     */
    private function countSelected(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (is_array($item) && ($item['selected'] ?? false)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Convert raw model data into DiscoveredModel DTOs.
     *
     * @param array<mixed> $modelsData
     *
     * @return list<DiscoveredModel>
     */
    private function buildDiscoveredModels(array $modelsData): array
    {
        $models = [];
        foreach ($modelsData as $modelData) {
            if (!is_array($modelData)) {
                continue;
            }
            $models[] = new DiscoveredModel(
                modelId: is_string($modelData['modelId'] ?? null) ? $modelData['modelId'] : '',
                name: is_string($modelData['name'] ?? null) ? $modelData['name'] : '',
                description: is_string($modelData['description'] ?? null) ? $modelData['description'] : '',
                capabilities: is_array($modelData['capabilities'] ?? null) ? array_values(array_filter($modelData['capabilities'], is_string(...))) : ['chat'],
                contextLength: is_numeric($modelData['contextLength'] ?? null) ? (int)$modelData['contextLength'] : 0,
                maxOutputTokens: is_numeric($modelData['maxOutputTokens'] ?? null) ? (int)$modelData['maxOutputTokens'] : 0,
                recommended: (bool)($modelData['recommended'] ?? false),
            );
        }

        return $models;
    }

    /**
     * Parse request body, handling both JSON and form data.
     *
     * @throws InvalidArgumentException when the request declares a JSON body that is not valid JSON
     *
     * @return array<string, mixed>|null
     */
    private function parseRequestBody(ServerRequestInterface $request): ?array
    {
        // First try getParsedBody (works for form data)
        $body = $request->getParsedBody();
        if (is_array($body) && $body !== []) {
            /** @var array<string, mixed> $body */
            return $body;
        }

        // For JSON requests, manually decode
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $contents = $request->getBody()->getContents();
            if ($contents !== '') {
                // A malformed JSON body is surfaced as a clear error instead of being
                // silently treated as empty, so the AJAX client sees the real cause
                // rather than a generic "fields required" message.
                try {
                    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new InvalidArgumentException('Invalid JSON request body: ' . $e->getMessage(), 1751280101, $e);
                }
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    return $decoded;
                }
            }
        }

        /** @var array<string, mixed>|null $body */
        return is_array($body) ? $body : null;
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
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    /**
     * Extract array value from request body.
     *
     * @return array<mixed>
     */
    private function extractArrayFromBody(mixed $body, string $key): array
    {
        if (!is_array($body)) {
            return [];
        }
        $value = $body[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /**
     * Extract integer value from request body.
     */
    private function extractIntFromBody(mixed $body, string $key, int $default = 0): int
    {
        if (!is_array($body)) {
            return $default;
        }
        $value = $body[$key] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }

    private function generateVaultIdentifier(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    /**
     * Generate a unique identifier from a name.
     */
    private function generateIdentifier(string $name): string
    {
        $identifier = strtolower(trim($name));
        $identifier = (string)preg_replace('/[^a-z0-9]+/', '-', $identifier);
        // Keep slug + '-' + 6-char suffix within the varchar(100)
        // identifier columns.
        $identifier = trim(mb_substr($identifier, 0, 93), '-');

        // Random suffix for uniqueness — a time()-based suffix is
        // identical for every record created in the same request batch.
        return $identifier . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Applies the TCA identifier contract (eval alphanum_x,lower, max 100)
     * to a caller-provided identifier — the AJAX path bypasses FormEngine.
     */
    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        $identifier = (string)preg_replace('/[^a-z0-9_-]+/', '-', $identifier);

        return mb_substr(trim($identifier, '-'), 0, 100);
    }

    /**
     * The wizard's AJAX payload bypasses FormEngine, so the TCA input
     * limit (max=255, matching the varchar(255) columns) is enforced
     * here — a strict-mode DBMS rejects overlong values instead of
     * truncating them like SQLite.
     */
    private function truncateLabel(string $value): string
    {
        return mb_substr(trim($value), 0, 255);
    }
}
