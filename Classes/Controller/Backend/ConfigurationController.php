<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ProviderModelsResponse;
use Netresearch\NrLlm\Controller\Backend\Response\SuccessResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConfigurationResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\WizardGeneratorService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for LLM configuration management.
 *
 * Uses TYPO3 FormEngine for record editing (TCA-based forms).
 * Custom actions for AJAX operations (toggle active, set default, test).
 */
#[AsController]
final class ConfigurationController extends ActionController
{
    private const TABLE_NAME = 'tx_nrllm_configuration';

    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly LlmConfigurationService $configurationService,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly ModelRepository $modelRepository,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly ProviderAdapterRegistry $providerAdapterRegistry,
        private readonly WizardGeneratorService $wizardGeneratorService,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        // Add module menu dropdown to docheader (shows all LLM sub-modules)
        $this->moduleTemplate->makeDocHeaderModuleMenu();

        // Register AJAX URLs for JavaScript
        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_config_toggle_active' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_config_toggle_active'),
            'nrllm_config_set_default' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_config_set_default'),
            'nrllm_config_test' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_config_test'),
        ]);

        // Load JavaScript for configuration list actions (ES6 module)
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ConfigurationList.js');
    }

    /**
     * List all LLM configurations.
     */
    public function listAction(): ResponseInterface
    {
        $configurations = $this->configurationRepository->findAll();

        // Build FormEngine URLs for each configuration
        /** @var array<int, string> $editUrls */
        $editUrls = [];
        foreach ($configurations as $config) {
            /** @var LlmConfiguration $config */
            $uid = $config->getUid();
            if ($uid === null) {
                continue;
            }
            $editUrls[$uid] = $this->buildEditUrl($uid);
        }

        $this->moduleTemplate->assignMultiple([
            'configurations' => $configurations,
            'providers' => $this->getProviderOptions(),
            'editUrls' => $editUrls,
            'newUrl' => $this->buildNewUrl(),
            'wizardUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_wizard'),
        ]);

        // Add shortcut/bookmark button to docheader (v14+)
        if (method_exists($this->moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm_configurations',
                displayName: 'LLM - Configurations',
            );
        }

        // Add "New Configuration" and "Create with AI" buttons to docheader
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $createButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.configuration.new', 'NrLlm') ?? 'New Configuration')
            ->setShowLabelText(true)
            ->setHref($this->buildNewUrl());
        $buttonBar->addButton($createButton);

        $aiButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-bolt', IconSize::SMALL))
            ->setTitle('Create with AI')
            ->setShowLabelText(true)
            ->setHref($this->uriBuilder->reset()->uriFor('wizardForm'));
        $buttonBar->addButton($aiButton, ButtonBar::BUTTON_POSITION_LEFT, 2);

        return $this->moduleTemplate->renderResponse('Backend/Configuration/List');
    }

    /**
     * Show the "Create with AI" wizard form for configurations.
     */
    public function wizardFormAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/WizardFormLoading.js');

        $availableConfigs = $this->configurationRepository->findActive();
        $resolvedConfig = $this->wizardGeneratorService->resolveConfiguration();

        $this->moduleTemplate->assignMultiple([
            'wizardType' => 'configuration',
            'availableConfigs' => $availableConfigs,
            'resolvedConfig' => $resolvedConfig,
        ]);
        return $this->moduleTemplate->renderResponse('Backend/Configuration/WizardForm');
    }

    /**
     * Generate configuration from description and show preview.
     */
    public function wizardGenerateAction(string $description = '', int $configurationUid = 0): ResponseInterface
    {
        $description = trim($description);
        if ($description === '') {
            $this->addFlashMessage('Please describe what this configuration should do.', 'Missing description', ContextualFeedbackSeverity::WARNING);
            return $this->redirect('wizardForm');
        }
        if (mb_strlen($description) > 2000) {
            $description = mb_substr($description, 0, 2000);
        }

        try {
            $config = $this->wizardGeneratorService->resolveConfiguration(
                $configurationUid > 0 ? $configurationUid : null,
            );
            $result = $this->wizardGeneratorService->generateConfiguration($description, $config);
            $this->moduleTemplate->assignMultiple([
                'wizardType' => 'configuration',
                'description' => $description,
                'generated' => $result,
                'newUrl' => $this->buildNewUrlWithDefaults('configuration', $result),
                'usedConfig' => $config,
                'configurationUid' => $configurationUid,
            ]);
            return $this->moduleTemplate->renderResponse('Backend/Configuration/WizardPreview');
        } catch (Throwable $e) {
            $this->addFlashMessage('Generation failed: ' . $e->getMessage(), 'Error', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('wizardForm');
        }
    }

    /**
     * AJAX: Toggle active status.
     */
    public function toggleActiveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse('No configuration UID specified'))->jsonSerialize(), 400);
        }

        $configuration = $this->configurationRepository->findByUid($uid);
        if ($configuration === null) {
            return new JsonResponse((new ErrorResponse('Configuration not found'))->jsonSerialize(), 404);
        }

        try {
            $this->configurationService->toggleActive($configuration);
            return new JsonResponse((new ToggleActiveResponse(
                success: true,
                isActive: $configuration->isActive(),
            ))->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }

    /**
     * AJAX: Set configuration as default.
     */
    public function setDefaultAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse('No configuration UID specified'))->jsonSerialize(), 400);
        }

        $configuration = $this->configurationRepository->findByUid($uid);
        if ($configuration === null) {
            return new JsonResponse((new ErrorResponse('Configuration not found'))->jsonSerialize(), 404);
        }

        try {
            $this->configurationService->setAsDefault($configuration);
            return new JsonResponse((new SuccessResponse())->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }

    /**
     * AJAX: Get available models for a provider.
     */
    public function getModelsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $providerKey = $this->extractStringFromBody($body, 'provider');

        if ($providerKey === '') {
            return new JsonResponse((new ErrorResponse('No provider specified'))->jsonSerialize(), 400);
        }

        try {
            $providers = $this->llmServiceManager->getAvailableProviders();
            if (!isset($providers[$providerKey])) {
                return new JsonResponse((new ErrorResponse('Provider not available'))->jsonSerialize(), 404);
            }

            $provider = $providers[$providerKey];
            $models = $provider->getAvailableModels();
            $defaultModel = $provider->getDefaultModel();

            return new JsonResponse((new ProviderModelsResponse(
                success: true,
                models: $models,
                defaultModel: $defaultModel,
            ))->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }

    /**
     * AJAX: Test configuration.
     */
    public function testConfigurationAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse('No configuration UID specified'))->jsonSerialize(), 400);
        }

        $configuration = $this->configurationRepository->findByUid($uid);
        if ($configuration === null) {
            return new JsonResponse((new ErrorResponse('Configuration not found'))->jsonSerialize(), 404);
        }

        try {
            $model = $configuration->getLlmModel();
            if ($model === null || $model->getProvider() === null) {
                return new JsonResponse((new ErrorResponse('Configuration has no model assigned'))->jsonSerialize(), 400);
            }

            $testPrompt = 'Hello, please respond with a brief greeting.';
            $adapter = $this->providerAdapterRegistry->createAdapterFromModel($model);
            $options = $configuration->toOptionsArray();
            $response = $adapter->complete($testPrompt, $options);

            return new JsonResponse(
                TestConfigurationResponse::fromCompletionResponse($response)->jsonSerialize(),
            );
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }

    /**
     * AJAX: Get parameter constraints for a specific model.
     *
     * Returns information about which parameters are supported, fixed, or
     * have different ranges for the selected model based on its provider's
     * adapter type and model characteristics (e.g., reasoning models).
     */
    public function getModelConstraintsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $modelUid = $this->extractIntFromBody($body, 'modelUid');

        if ($modelUid === 0) {
            return new JsonResponse(['success' => true, 'constraints' => $this->getDefaultConstraints()]);
        }

        $model = $this->modelRepository->findByUid($modelUid);
        if ($model === null) {
            return new JsonResponse(['success' => true, 'constraints' => $this->getDefaultConstraints()]);
        }

        $provider = $model->getProvider();
        $adapterType = $provider?->getAdapterType() ?? '';
        $modelId = $model->getModelId();

        $constraints = $this->buildConstraints($adapterType, $modelId);

        return new JsonResponse(['success' => true, 'constraints' => $constraints]);
    }

    /**
     * Build parameter constraints based on adapter type and model ID.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildConstraints(string $adapterType, string $modelId): array
    {
        $isReasoning = $this->isReasoningModel($adapterType, $modelId);

        return match ($adapterType) {
            'anthropic' => $this->getAnthropicConstraints(),
            'gemini' => $this->getGeminiConstraints(),
            'openai', 'azure_openai' => $isReasoning
                ? $this->getOpenAIReasoningConstraints()
                : $this->getOpenAIChatConstraints(),
            'ollama' => $this->getOllamaConstraints(),
            default => $isReasoning
                ? $this->getOpenAIReasoningConstraints()
                : $this->getDefaultConstraints(),
        };
    }

    /**
     * Detect if a model is a reasoning model (OpenAI o-series, GPT-5.x).
     */
    private function isReasoningModel(string $adapterType, string $modelId): bool
    {
        if (in_array($adapterType, ['openai', 'azure_openai', 'openrouter', 'groq', 'custom'], true)) {
            return (bool)preg_match('/^(o[1-9]|gpt-5)/', $modelId);
        }
        return false;
    }

    /**
     * Default constraints — all parameters available with standard ranges.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getDefaultConstraints(): array
    {
        return [
            'temperature' => ['supported' => true, 'min' => 0.0, 'max' => 2.0],
            'top_p' => ['supported' => true, 'min' => 0.0, 'max' => 1.0],
            'frequency_penalty' => ['supported' => true, 'min' => -2.0, 'max' => 2.0],
            'presence_penalty' => ['supported' => true, 'min' => -2.0, 'max' => 2.0],
        ];
    }

    /**
     * OpenAI chat model constraints.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getOpenAIChatConstraints(): array
    {
        return [
            'temperature' => ['supported' => true, 'min' => 0.0, 'max' => 2.0],
            'top_p' => ['supported' => true, 'min' => 0.0, 'max' => 1.0],
            'frequency_penalty' => ['supported' => true, 'min' => -2.0, 'max' => 2.0],
            'presence_penalty' => ['supported' => true, 'min' => -2.0, 'max' => 2.0],
        ];
    }

    /**
     * OpenAI reasoning model constraints — sampling parameters fixed/unsupported.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getOpenAIReasoningConstraints(): array
    {
        return [
            'temperature' => ['supported' => true, 'fixed' => 1.0, 'hint' => 'Fixed at 1.0 for reasoning models'],
            'top_p' => ['supported' => true, 'fixed' => 1.0, 'hint' => 'Fixed at 1.0 for reasoning models'],
            'frequency_penalty' => ['supported' => false, 'hint' => 'Not supported by reasoning models'],
            'presence_penalty' => ['supported' => false, 'hint' => 'Not supported by reasoning models'],
        ];
    }

    /**
     * Anthropic (Claude) constraints.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getAnthropicConstraints(): array
    {
        return [
            'temperature' => ['supported' => true, 'min' => 0.0, 'max' => 1.0],
            'top_p' => ['supported' => true, 'min' => 0.0, 'max' => 1.0, 'hint' => 'Mutually exclusive with temperature'],
            'frequency_penalty' => ['supported' => false, 'hint' => 'Not supported by Anthropic'],
            'presence_penalty' => ['supported' => false, 'hint' => 'Not supported by Anthropic'],
        ];
    }

    /**
     * Gemini constraints.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getGeminiConstraints(): array
    {
        return [
            'temperature' => ['supported' => true, 'min' => 0.0, 'max' => 2.0],
            'top_p' => ['supported' => true, 'min' => 0.0, 'max' => 1.0],
            'frequency_penalty' => ['supported' => false, 'hint' => 'Not supported by Gemini'],
            'presence_penalty' => ['supported' => false, 'hint' => 'Not supported by Gemini'],
        ];
    }

    /**
     * Ollama constraints — local models, flexible ranges.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getOllamaConstraints(): array
    {
        return [
            'temperature' => ['supported' => true, 'min' => 0.0, 'max' => 2.0],
            'top_p' => ['supported' => true, 'min' => 0.0, 'max' => 1.0],
            'frequency_penalty' => ['supported' => true, 'min' => -2.0, 'max' => 2.0],
            'presence_penalty' => ['supported' => true, 'min' => -2.0, 'max' => 2.0],
        ];
    }

    /**
     * Get provider options for select field.
     *
     * @return array<string, string>
     */
    private function getProviderOptions(): array
    {
        $providers = $this->llmServiceManager->getProviderList();
        $available = $this->llmServiceManager->getAvailableProviders();

        $options = [];
        foreach ($providers as $identifier => $name) {
            $suffix = isset($available[$identifier]) ? '' : ' ' . (LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:configuration.notConfigured', 'NrLlm') ?? '(not configured)');
            $options[$identifier] = $name . $suffix;
        }

        return $options;
    }

    /**
     * Build FormEngine new record URL with pre-filled defaults from AI generation.
     *
     * @param array<string, mixed> $data
     */
    private function buildNewUrlWithDefaults(string $type, array $data): string
    {
        $table = $type === 'configuration' ? 'tx_nrllm_configuration' : 'tx_nrllm_task';
        $params = [
            'edit' => [$table => [0 => 'new']],
            'returnUrl' => $this->buildReturnUrl(),
        ];
        foreach ($data as $key => $value) {
            if ($value !== '' && $value !== null && $key !== 'generated' && $key !== 'recommended_model') {
                $params['defVals[' . $table . '][' . $key . ']'] = is_string($value) || is_numeric($value) ? (string)$value : '';
            }
        }
        return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', $params);
    }

    /**
     * Build FormEngine edit URL for a configuration.
     */
    private function buildEditUrl(int $uid): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::TABLE_NAME => [$uid => 'edit']],
            'returnUrl' => $this->buildReturnUrl(),
        ]);
    }

    /**
     * Build FormEngine new record URL.
     */
    private function buildNewUrl(): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::TABLE_NAME => [0 => 'new']],
            'returnUrl' => $this->buildReturnUrl(),
        ]);
    }

    /**
     * Build return URL for FormEngine (back to list).
     */
    private function buildReturnUrl(): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_configurations');
    }

    /**
     * Extract integer value from request body.
     */
    private function extractIntFromBody(mixed $body, string $key): int
    {
        if (!is_array($body)) {
            return 0;
        }

        $value = $body[$key] ?? 0;

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Extract string value from request body.
     */
    private function extractStringFromBody(mixed $body, string $key): string
    {
        if (!is_array($body)) {
            return '';
        }

        $value = $body[$key] ?? '';

        return is_string($value) || is_numeric($value) ? (string)$value : '';
    }
}
