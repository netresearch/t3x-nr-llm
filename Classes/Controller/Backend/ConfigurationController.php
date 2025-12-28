<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ProviderModelsResponse;
use Netresearch\NrLlm\Controller\Backend\Response\SuccessResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConfigurationResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for LLM configuration management.
 */
#[AsController]
final class ConfigurationController extends ActionController
{
    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ComponentFactory $componentFactory,
        private readonly IconFactory $iconFactory,
        private readonly LlmConfigurationService $configurationService,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly ModelRepository $modelRepository,
        private readonly ProviderRepository $providerRepository,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly ProviderAdapterRegistry $providerAdapterRegistry,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        // Register AJAX URLs for JavaScript
        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_config_toggle_active' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_config_toggle_active'),
            'nrllm_config_set_default' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_config_set_default'),
            'nrllm_config_test' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_config_test'),
        ]);

        // Load JavaScript for configuration list actions
        $this->pageRenderer->addJsFile(
            'EXT:nr_llm/Resources/Public/JavaScript/Backend/ConfigurationList.js',
            'text/javascript',
            false,
            false,
            '',
            false,
            '|',
            false,
            '',
            true,
        );
    }

    /**
     * List all LLM configurations.
     */
    public function listAction(): ResponseInterface
    {
        $configurations = $this->configurationRepository->findAll();

        // Build lookup maps for models and providers
        $models = $this->modelRepository->findAll();
        $providers = $this->providerRepository->findActive();

        $providerMap = [];
        foreach ($providers as $provider) {
            $uid = $provider->getUid();
            if ($uid !== null) {
                $providerMap[$uid] = $provider;
            }
        }

        $modelMap = [];
        foreach ($models as $model) {
            if (!$model instanceof Model) {
                continue;
            }
            $uid = $model->getUid();
            if ($uid !== null) {
                // Populate provider on model
                if ($model->getProviderUid() > 0) {
                    $model->setProvider($providerMap[$model->getProviderUid()] ?? null);
                }
                $modelMap[$uid] = $model;
            }
        }

        // Populate llmModel on configurations
        foreach ($configurations as $config) {
            if ($config instanceof LlmConfiguration && $config->getModelUid() > 0) {
                $config->setLlmModel($modelMap[$config->getModelUid()] ?? null);
            }
        }

        $this->moduleTemplate->assignMultiple([
            'configurations' => $configurations,
            'providers' => $this->getProviderOptions(),
        ]);

        // Add "New Configuration" button to docheader
        $createButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.configuration.new', 'NrLlm') ?? 'New Configuration')
            ->setShowLabelText(true)
            ->setHref((string)$this->uriBuilder->reset()->uriFor('edit'));
        $this->moduleTemplate->addButtonToButtonBar($createButton);

        return $this->moduleTemplate->renderResponse('Backend/Configuration/List');
    }

    /**
     * Show edit form for new or existing configuration.
     */
    public function editAction(?int $uid = null): ResponseInterface
    {
        $configuration = null;
        if ($uid !== null) {
            $configuration = $this->configurationRepository->findByUid($uid);
            if ($configuration === null) {
                $this->addFlashMessage(
                    'Configuration not found',
                    'Error',
                    ContextualFeedbackSeverity::ERROR,
                );
                return new RedirectResponse(
                    $this->uriBuilder->reset()->uriFor('list'),
                );
            }
        }

        // Load models with hydrated provider relations for dropdown display
        $models = $this->modelRepository->findActive();
        $this->hydrateModelsProviderRelations($models);

        $this->moduleTemplate->assignMultiple([
            'configuration' => $configuration,
            'models' => $models,
            'isNew' => $configuration === null,
        ]);

        // Add "Back to List" button to docheader
        $backButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.back', 'NrLlm') ?? 'Back to List')
            ->setShowLabelText(true)
            ->setHref((string)$this->uriBuilder->reset()->uriFor('list'));
        $this->moduleTemplate->addButtonToButtonBar($backButton);

        return $this->moduleTemplate->renderResponse('Backend/Configuration/Edit');
    }

    /**
     * Create new configuration.
     */
    public function createAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $data = $this->extractConfigurationData($body);

        $configuration = new LlmConfiguration();
        $this->mapDataToConfiguration($configuration, $data);

        // Validate identifier uniqueness
        if (!$this->configurationService->isIdentifierAvailable($configuration->getIdentifier())) {
            $this->addFlashMessage(
                sprintf('Identifier "%s" is already in use', $configuration->getIdentifier()),
                'Validation Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('edit'),
            );
        }

        try {
            $this->configurationService->create($configuration);
            $this->addFlashMessage(
                sprintf('Configuration "%s" created successfully', $configuration->getName()),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error creating configuration: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list'),
        );
    }

    /**
     * Update existing configuration.
     */
    public function updateAction(int $uid): ResponseInterface
    {
        $configuration = $this->configurationRepository->findByUid($uid);

        if ($configuration === null) {
            $this->addFlashMessage(
                'Configuration not found',
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list'),
            );
        }

        $body = $this->request->getParsedBody();
        $data = $this->extractConfigurationData($body);

        // Validate identifier uniqueness (excluding current record)
        $newIdentifier = $data['identifier'] ?? '';
        if (is_string($newIdentifier) && $newIdentifier !== $configuration->getIdentifier()
            && !$this->configurationService->isIdentifierAvailable($newIdentifier, $uid)
        ) {
            $this->addFlashMessage(
                sprintf('Identifier "%s" is already in use', $newIdentifier),
                'Validation Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('edit', ['uid' => $uid]),
            );
        }

        $this->mapDataToConfiguration($configuration, $data);

        try {
            $this->configurationService->update($configuration);
            $this->addFlashMessage(
                sprintf('Configuration "%s" updated successfully', $configuration->getName()),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error updating configuration: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list'),
        );
    }

    /**
     * Delete configuration.
     */
    public function deleteAction(int $uid): ResponseInterface
    {
        $configuration = $this->configurationRepository->findByUid($uid);

        if ($configuration === null) {
            $this->addFlashMessage(
                'Configuration not found',
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list'),
            );
        }

        try {
            $name = $configuration->getName();
            $this->configurationService->delete($configuration);
            $this->addFlashMessage(
                sprintf('Configuration "%s" deleted successfully', $name),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error deleting configuration: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list'),
        );
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

        // Populate Model→Provider relations for proper provider resolution
        $this->hydrateConfigurationRelations($configuration);

        try {
            $testPrompt = 'Hello, please respond with a brief greeting.';

            // Use database Provider/Model when available (uses correct endpoint_url)
            $model = $configuration->getLlmModel();
            if ($model !== null && $model->getProvider() !== null) {
                $adapter = $this->providerAdapterRegistry->createAdapterFromModel($model);
                $options = $configuration->toOptionsArray();
                $response = $adapter->complete($testPrompt, $options);
            } else {
                // Fall back to ext_conf providers for legacy configurations
                $chatOptions = $configuration->toChatOptions();
                $response = $this->llmServiceManager->complete($testPrompt, $chatOptions);
            }

            return new JsonResponse(
                TestConfigurationResponse::fromCompletionResponse($response)->jsonSerialize(),
            );
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
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
            $suffix = isset($available[$identifier]) ? '' : ' (not configured)';
            $options[$identifier] = $name . $suffix;
        }

        return $options;
    }

    /**
     * Extract configuration data from request body.
     *
     * @return array<string, mixed>
     */
    private function extractConfigurationData(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $configuration = $body['configuration'] ?? [];

        if (!is_array($configuration)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = $configuration;

        return $result;
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

    /**
     * Hydrate Model→Provider relations for a configuration.
     *
     * Extbase doesn't automatically load nested relations, so we need to
     * manually populate the Model and Provider objects for proper resolution.
     */
    private function hydrateConfigurationRelations(LlmConfiguration $configuration): void
    {
        if ($configuration->getModelUid() <= 0) {
            return;
        }

        $model = $this->modelRepository->findByUid($configuration->getModelUid());
        if ($model === null) {
            return;
        }

        // Populate provider on model
        if ($model->getProviderUid() > 0) {
            $provider = $this->providerRepository->findByUid($model->getProviderUid());
            if ($provider !== null) {
                $model->setProvider($provider);
            }
        }

        $configuration->setLlmModel($model);
    }

    /**
     * Hydrate Provider relations for a collection of models.
     *
     * Extbase doesn't automatically lazy-load relations when iterating,
     * so we need to manually populate Provider objects for display.
     *
     * @param iterable<Model> $models
     */
    private function hydrateModelsProviderRelations(iterable $models): void
    {
        foreach ($models as $model) {
            if ($model->getProviderUid() > 0 && $model->getProvider() === null) {
                $provider = $this->providerRepository->findByUid($model->getProviderUid());
                if ($provider !== null) {
                    $model->setProvider($provider);
                }
            }
        }
    }

    /**
     * Map form data to configuration entity.
     *
     * @param array<string, mixed> $data
     */
    private function mapDataToConfiguration(LlmConfiguration $configuration, array $data): void
    {
        if (isset($data['identifier']) && is_scalar($data['identifier'])) {
            $configuration->setIdentifier((string)$data['identifier']);
        }
        if (isset($data['name']) && is_scalar($data['name'])) {
            $configuration->setName((string)$data['name']);
        }
        if (isset($data['description']) && is_scalar($data['description'])) {
            $configuration->setDescription((string)$data['description']);
        }
        if (isset($data['modelUid']) && is_numeric($data['modelUid'])) {
            $modelUid = (int)$data['modelUid'];
            if ($modelUid > 0) {
                $model = $this->modelRepository->findByUid($modelUid);
                if ($model !== null) {
                    $configuration->setLlmModel($model);
                }
            } else {
                $configuration->setLlmModel(null);
            }
        }
        if (isset($data['translator']) && is_scalar($data['translator'])) {
            $configuration->setTranslator((string)$data['translator']);
        }
        if (isset($data['systemPrompt']) && is_scalar($data['systemPrompt'])) {
            $configuration->setSystemPrompt((string)$data['systemPrompt']);
        }
        if (isset($data['temperature']) && is_numeric($data['temperature'])) {
            $configuration->setTemperature((float)$data['temperature']);
        }
        if (isset($data['maxTokens']) && is_numeric($data['maxTokens'])) {
            $configuration->setMaxTokens((int)$data['maxTokens']);
        }
        if (isset($data['topP']) && is_numeric($data['topP'])) {
            $configuration->setTopP((float)$data['topP']);
        }
        if (isset($data['frequencyPenalty']) && is_numeric($data['frequencyPenalty'])) {
            $configuration->setFrequencyPenalty((float)$data['frequencyPenalty']);
        }
        if (isset($data['presencePenalty']) && is_numeric($data['presencePenalty'])) {
            $configuration->setPresencePenalty((float)$data['presencePenalty']);
        }
        if (isset($data['options']) && is_scalar($data['options'])) {
            $configuration->setOptions((string)$data['options']);
        }
        if (isset($data['maxRequestsPerDay']) && is_numeric($data['maxRequestsPerDay'])) {
            $configuration->setMaxRequestsPerDay((int)$data['maxRequestsPerDay']);
        }
        if (isset($data['maxTokensPerDay']) && is_numeric($data['maxTokensPerDay'])) {
            $configuration->setMaxTokensPerDay((int)$data['maxTokensPerDay']);
        }
        if (isset($data['maxCostPerDay']) && is_numeric($data['maxCostPerDay'])) {
            $configuration->setMaxCostPerDay((float)$data['maxCostPerDay']);
        }
        if (isset($data['isActive'])) {
            $configuration->setIsActive((bool)$data['isActive']);
        }
        if (isset($data['isDefault'])) {
            $configuration->setIsDefault((bool)$data['isDefault']);
        }
    }
}
