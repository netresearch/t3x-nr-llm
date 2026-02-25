<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Service\SetupWizard\ConfigurationGenerator;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\DTO\SuggestedConfiguration;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Netresearch\NrLlm\Service\SetupWizard\ProviderDetector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
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
        private readonly ComponentFactory $componentFactory,
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
        $refreshButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL))
            ->setTitle('Refresh')
            ->setShowLabelText(true)
            ->setHref((string)$this->backendUriBuilder->buildUriFromRoute('nrllm_wizard'));
        $buttonBar->addButton($refreshButton);

        // Add shortcut/bookmark button to docheader
        $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            routeIdentifier: 'nrllm_wizard',
            displayName: 'LLM - Setup Wizard',
        );

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
        $body = $request->getParsedBody();
        $endpoint = $this->extractStringFromBody($body, 'endpoint');

        if ($endpoint === '') {
            return new JsonResponse([
                'success' => false,
                'error' => 'Endpoint URL is required',
            ], 400);
        }

        $detected = $this->providerDetector->detect($endpoint);

        return new JsonResponse([
            'success' => true,
            'provider' => $detected->toArray(),
        ]);
    }

    /**
     * AJAX: Test connection to provider.
     */
    public function testAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $endpoint = $this->extractStringFromBody($body, 'endpoint');
        $apiKey = $this->extractStringFromBody($body, 'apiKey');
        $adapterType = $this->extractStringFromBody($body, 'adapterType', 'openai');

        if ($endpoint === '') {
            return new JsonResponse([
                'success' => false,
                'error' => 'Endpoint URL is required',
            ], 400);
        }

        $detected = new DetectedProvider(
            adapterType: $adapterType,
            suggestedName: 'Test Provider',
            endpoint: $endpoint,
        );

        $result = $this->modelDiscovery->testConnection($detected, $apiKey);

        return new JsonResponse([
            'success' => $result['success'],
            'message' => $result['message'],
        ]);
    }

    /**
     * AJAX: Discover available models from provider.
     */
    public function discoverAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $endpoint = $this->extractStringFromBody($body, 'endpoint');
        $apiKey = $this->extractStringFromBody($body, 'apiKey');
        $adapterType = $this->extractStringFromBody($body, 'adapterType', 'openai');

        if ($endpoint === '') {
            return new JsonResponse([
                'success' => false,
                'error' => 'Endpoint URL is required',
            ], 400);
        }

        $detected = new DetectedProvider(
            adapterType: $adapterType,
            suggestedName: 'Provider',
            endpoint: $endpoint,
        );

        $models = $this->modelDiscovery->discover($detected, $apiKey);

        return new JsonResponse([
            'success' => true,
            'models' => array_map(fn(DiscoveredModel $m) => $m->toArray(), $models),
        ]);
    }

    /**
     * AJAX: Generate configuration suggestions using the LLM.
     */
    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->parseRequestBody($request);
        $endpoint = $this->extractStringFromBody($body, 'endpoint');
        $apiKey = $this->extractStringFromBody($body, 'apiKey');
        $adapterType = $this->extractStringFromBody($body, 'adapterType', 'openai');
        $modelsData = $this->extractArrayFromBody($body, 'models');

        if ($endpoint === '' || $modelsData === []) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Endpoint and models are required',
            ], 400);
        }

        $detected = new DetectedProvider(
            adapterType: $adapterType,
            suggestedName: 'Provider',
            endpoint: $endpoint,
        );

        // Convert model data to DTOs
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

        $configurations = $this->configurationGenerator->generate($detected, $apiKey, $models);

        return new JsonResponse([
            'success' => true,
            'configurations' => array_map(fn(SuggestedConfiguration $c) => $c->toArray(), $configurations),
        ]);
    }

    /**
     * AJAX: Save wizard results to database.
     */
    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->parseRequestBody($request);
        $providerData = $this->extractArrayFromBody($body, 'provider');
        $modelsData = $this->extractArrayFromBody($body, 'models');
        $configurationsData = $this->extractArrayFromBody($body, 'configurations');
        $pid = $this->extractIntFromBody($body, 'pid');

        if ($providerData === [] || $modelsData === []) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Provider and models are required',
            ], 400);
        }

        try {
            // Create provider
            $providerName = is_string($providerData['suggestedName'] ?? null) ? $providerData['suggestedName'] : 'provider';
            $providerAdapter = is_string($providerData['adapterType'] ?? null) ? $providerData['adapterType'] : 'openai';
            $providerEndpoint = is_string($providerData['endpoint'] ?? null) ? $providerData['endpoint'] : '';
            $providerApiKey = is_string($providerData['apiKey'] ?? null) ? $providerData['apiKey'] : '';

            $provider = new Provider();
            $provider->setIdentifier($this->generateIdentifier($providerName));
            $provider->setName($providerName !== '' ? $providerName : 'New Provider');
            $provider->setAdapterType($providerAdapter);
            $provider->setEndpointUrl($providerEndpoint);
            $provider->setApiKey($providerApiKey);
            $provider->setIsActive(true);
            if ($pid >= 0) {
                $provider->setPid($pid);
            }

            $this->providerRepository->add($provider);
            $this->persistenceManager->persistAll();

            $providerUid = $provider->getUid();
            /** @var array<string, Model> $savedModels */
            $savedModels = [];

            // Check if any model will be set as default
            $hasNewDefault = false;
            foreach ($modelsData as $modelData) {
                if (is_array($modelData) && ($modelData['selected'] ?? false) && ($modelData['recommended'] ?? false)) {
                    $hasNewDefault = true;
                    break;
                }
            }

            // Clear existing defaults before setting new ones
            if ($hasNewDefault) {
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
                $modelCapabilities = is_array($modelData['capabilities'] ?? null) ? $modelData['capabilities'] : ['chat'];
                $contextLength = is_numeric($modelData['contextLength'] ?? null) ? (int)$modelData['contextLength'] : 0;
                $maxOutputTokens = is_numeric($modelData['maxOutputTokens'] ?? null) ? (int)$modelData['maxOutputTokens'] : 0;

                $model = new Model();
                $model->setIdentifier($this->generateIdentifier($modelName));
                $model->setName($modelName);
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

            $this->persistenceManager->persistAll();

            // Create configurations
            foreach ($configurationsData as $configData) {
                if (!is_array($configData)) {
                    continue;
                }
                if (!($configData['selected'] ?? false)) {
                    continue;
                }

                $recommendedModelId = is_string($configData['recommendedModelId'] ?? null) ? $configData['recommendedModelId'] : '';
                $model = $savedModels[$recommendedModelId] ?? reset($savedModels) ?: null;

                if ($model === null) {
                    continue;
                }

                $configName = is_string($configData['name'] ?? null) ? $configData['name'] : 'config';
                $configIdentifier = is_string($configData['identifier'] ?? null) ? $configData['identifier'] : '';
                $configDescription = is_string($configData['description'] ?? null) ? $configData['description'] : '';
                $systemPrompt = is_string($configData['systemPrompt'] ?? null) ? $configData['systemPrompt'] : '';
                $temperature = is_numeric($configData['temperature'] ?? null) ? (float)$configData['temperature'] : 0.7;
                $maxTokens = is_numeric($configData['maxTokens'] ?? null) ? (int)$configData['maxTokens'] : 4096;

                $config = new LlmConfiguration();
                $config->setIdentifier($configIdentifier !== '' ? $configIdentifier : $this->generateIdentifier($configName));
                $config->setName($configName);
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

            $this->persistenceManager->persistAll();

            $configCount = 0;
            foreach ($configurationsData as $c) {
                if (is_array($c) && ($c['selected'] ?? false)) {
                    $configCount++;
                }
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Configuration saved successfully',
                'provider' => [
                    'uid' => $providerUid,
                    'name' => $provider->getName(),
                ],
                'modelsCount' => count($savedModels),
                'configurationsCount' => $configCount,
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to save: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse request body, handling both JSON and form data.
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
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    return $decoded;
                }
            }
        }

        if (is_array($body)) {
            /** @var array<string, mixed> $body */
            return $body;
        }

        return null;
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

    /**
     * Generate a unique identifier from a name.
     */
    private function generateIdentifier(string $name): string
    {
        $identifier = strtolower(trim($name));
        $identifier = (string)preg_replace('/[^a-z0-9]+/', '-', $identifier);
        $identifier = trim($identifier, '-');

        // Add timestamp suffix for uniqueness
        return $identifier . '-' . substr(md5((string)time()), 0, 6);
    }
}
