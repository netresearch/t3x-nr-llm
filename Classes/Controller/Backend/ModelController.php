<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\DTO\ModelFormInput;
use Netresearch\NrLlm\Controller\Backend\DTO\ModelFormInputFactory;
use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ModelListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\SuccessResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConnectionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
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
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for LLM Model management.
 */
#[AsController]
final class ModelController extends ActionController
{
    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ComponentFactory $componentFactory,
        private readonly IconFactory $iconFactory,
        private readonly ModelRepository $modelRepository,
        private readonly ProviderRepository $providerRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly ProviderAdapterRegistry $providerAdapterRegistry,
        private readonly ModelDiscoveryInterface $modelDiscovery,
        private readonly ModelFormInputFactory $formInputFactory,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        // Add module menu dropdown to docheader (shows all LLM sub-modules)
        $this->moduleTemplate->makeDocHeaderModuleMenu();

        // Register AJAX URLs for JavaScript
        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_model_toggle_active' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_model_toggle_active'),
            'nrllm_model_set_default' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_model_set_default'),
            'nrllm_model_test' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_model_test'),
            'nrllm_model_fetch_available' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_model_fetch_available'),
            'nrllm_model_detect_limits' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_model_detect_limits'),
        ]);

        // Load JavaScript for model list actions (ES6 module)
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ModelList.js');
    }

    /**
     * List all models.
     */
    public function listAction(): ResponseInterface
    {
        $models = $this->modelRepository->findAll();
        $providers = $this->providerRepository->findActive();

        // Note: Provider relations are lazy-loaded by Extbase when accessed via $model->getProvider()

        $this->moduleTemplate->assignMultiple([
            'models' => $models,
            'providers' => $providers,
            'capabilities' => Model::getAllCapabilities(),
        ]);

        // Add shortcut/bookmark button to docheader
        $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            routeIdentifier: 'nrllm_models',
            displayName: 'LLM - Models',
        );

        // Add "New Model" button to docheader
        $createButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.model.new', 'NrLlm') ?? 'New Model')
            ->setShowLabelText(true)
            ->setHref((string)$this->uriBuilder->reset()->uriFor('edit'));
        $this->moduleTemplate->addButtonToButtonBar($createButton);

        return $this->moduleTemplate->renderResponse('Backend/Model/List');
    }

    /**
     * Show edit form for new or existing model.
     */
    public function editAction(?int $uid = null): ResponseInterface
    {
        $model = null;
        if ($uid !== null) {
            $model = $this->modelRepository->findByUid($uid);
            if ($model === null) {
                $this->addFlashMessage(
                    'Model not found',
                    'Error',
                    ContextualFeedbackSeverity::ERROR,
                );
                return new RedirectResponse(
                    $this->uriBuilder->reset()->uriFor('list'),
                );
            }
        }

        $selectedCapabilities = $model?->getCapabilitiesArray() ?? [];
        $this->moduleTemplate->assignMultiple([
            'model' => $model,
            'providers' => $this->providerRepository->findActive(),
            'capabilities' => Model::getAllCapabilities(),
            'selectedCapabilities' => $selectedCapabilities,
            'isNew' => $model === null,
            // Individual capability flags for reliable template rendering
            'hasChat' => in_array(Model::CAPABILITY_CHAT, $selectedCapabilities, true),
            'hasCompletion' => in_array(Model::CAPABILITY_COMPLETION, $selectedCapabilities, true),
            'hasEmbeddings' => in_array(Model::CAPABILITY_EMBEDDINGS, $selectedCapabilities, true),
            'hasVision' => in_array(Model::CAPABILITY_VISION, $selectedCapabilities, true),
            'hasStreaming' => in_array(Model::CAPABILITY_STREAMING, $selectedCapabilities, true),
            'hasTools' => in_array(Model::CAPABILITY_TOOLS, $selectedCapabilities, true),
            'hasJsonMode' => in_array(Model::CAPABILITY_JSON_MODE, $selectedCapabilities, true),
            'hasAudio' => in_array(Model::CAPABILITY_AUDIO, $selectedCapabilities, true),
        ]);

        // Add shortcut/bookmark button to docheader
        $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            routeIdentifier: 'nrllm_models',
            displayName: $model !== null
                ? sprintf('LLM - Model: %s', $model->getName())
                : 'LLM - New Model',
            arguments: $model !== null ? ['uid' => $model->getUid()] : [],
        );

        // Add "Back to List" button to docheader
        $backButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.back', 'NrLlm') ?? 'Back to List')
            ->setShowLabelText(true)
            ->setHref((string)$this->uriBuilder->reset()->uriFor('list'));
        $this->moduleTemplate->addButtonToButtonBar($backButton);

        // Load ModelForm JavaScript for fetch/detect functionality
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ModelForm.js');

        return $this->moduleTemplate->renderResponse('Backend/Model/Edit');
    }

    /**
     * Create new model.
     */
    public function createAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $data = $this->extractModelData($body);

        // Create DTO from form data and use factory to create entity
        $formInput = ModelFormInput::fromRequestData($data);
        $model = $this->formInputFactory->createFromInput($formInput);

        // Validate identifier uniqueness
        if (!$this->modelRepository->isIdentifierUnique($model->getIdentifier())) {
            $this->addFlashMessage(
                sprintf('Identifier "%s" is already in use', $model->getIdentifier()),
                'Validation Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('edit'),
            );
        }

        try {
            $this->modelRepository->add($model);
            $this->persistenceManager->persistAll();
            $this->addFlashMessage(
                sprintf('Model "%s" created successfully', $model->getName()),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error creating model: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list'),
        );
    }

    /**
     * Update existing model.
     */
    public function updateAction(int $uid): ResponseInterface
    {
        $model = $this->modelRepository->findByUid($uid);

        if ($model === null) {
            $this->addFlashMessage(
                'Model not found',
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list'),
            );
        }

        $body = $this->request->getParsedBody();
        $data = $this->extractModelData($body);

        // Create DTO from form data
        $formInput = ModelFormInput::fromRequestData($data);

        // Validate identifier uniqueness (excluding current record)
        if ($formInput->identifier !== $model->getIdentifier()
            && !$this->modelRepository->isIdentifierUnique($formInput->identifier, $uid)
        ) {
            $this->addFlashMessage(
                sprintf('Identifier "%s" is already in use', $formInput->identifier),
                'Validation Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('edit', ['uid' => $uid]),
            );
        }

        // Use factory to update entity from DTO
        $this->formInputFactory->updateFromInput($model, $formInput);

        try {
            $this->modelRepository->update($model);
            $this->persistenceManager->persistAll();
            $this->addFlashMessage(
                sprintf('Model "%s" updated successfully', $model->getName()),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error updating model: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list'),
        );
    }

    /**
     * Delete model.
     */
    public function deleteAction(int $uid): ResponseInterface
    {
        $model = $this->modelRepository->findByUid($uid);

        if ($model === null) {
            $this->addFlashMessage(
                'Model not found',
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list'),
            );
        }

        try {
            $name = $model->getName();
            $this->modelRepository->remove($model);
            $this->persistenceManager->persistAll();
            $this->addFlashMessage(
                sprintf('Model "%s" deleted successfully', $name),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error deleting model: ' . $e->getMessage(),
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
            return new JsonResponse((new ErrorResponse('No model UID specified'))->jsonSerialize(), 400);
        }

        $model = $this->modelRepository->findByUid($uid);
        if ($model === null) {
            return new JsonResponse((new ErrorResponse('Model not found'))->jsonSerialize(), 404);
        }

        try {
            $model->setIsActive(!$model->isActive());
            $this->modelRepository->update($model);
            $this->persistenceManager->persistAll();
            return new JsonResponse((new ToggleActiveResponse(
                success: true,
                isActive: $model->isActive(),
            ))->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }

    /**
     * AJAX: Set model as default.
     */
    public function setDefaultAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse('No model UID specified'))->jsonSerialize(), 400);
        }

        $model = $this->modelRepository->findByUid($uid);
        if ($model === null) {
            return new JsonResponse((new ErrorResponse('Model not found'))->jsonSerialize(), 404);
        }

        try {
            $this->modelRepository->setAsDefault($model);
            $this->persistenceManager->persistAll();
            return new JsonResponse((new SuccessResponse())->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }

    /**
     * AJAX: Get models by provider.
     */
    public function getByProviderAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $providerUid = $this->extractIntFromBody($body, 'providerUid');

        if ($providerUid === 0) {
            return new JsonResponse((new ErrorResponse('No provider UID specified'))->jsonSerialize(), 400);
        }

        try {
            $models = $this->modelRepository->findByProviderUid($providerUid);
            return new JsonResponse(ModelListResponse::fromModels($models)->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }

    /**
     * AJAX: Test model by making a simple API call.
     */
    public function testModelAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse('No model UID specified'))->jsonSerialize(), 400);
        }

        $model = $this->modelRepository->findByUid($uid);
        if ($model === null) {
            return new JsonResponse((new ErrorResponse('Model not found'))->jsonSerialize(), 404);
        }

        // Provider is lazy-loaded by Extbase
        if ($model->getProvider() === null) {
            return new JsonResponse((new ErrorResponse('Model has no provider configured'))->jsonSerialize(), 400);
        }

        try {
            // Create adapter from model's provider
            $adapter = $this->providerAdapterRegistry->createAdapterFromModel($model);

            // Make a simple test call - use enough tokens for models with thinking
            $testPrompt = 'Respond with exactly one word: Hello';
            $response = $adapter->complete($testPrompt, [
                'model' => $model->getModelId(),
                'max_tokens' => 100,
                'temperature' => 0.0,
            ]);

            $responseText = trim($response->content);

            // Build success message
            if ($responseText !== '') {
                $message = sprintf(
                    'Model "%s" responded: "%s" (tokens: %d in, %d out)',
                    $model->getName(),
                    mb_substr($responseText, 0, 100) . (mb_strlen($responseText) > 100 ? '...' : ''),
                    $response->usage->promptTokens,
                    $response->usage->completionTokens,
                );
            } else {
                // Model connected but returned empty content (might be using thinking mode)
                $message = sprintf(
                    'Model "%s" connected successfully (tokens: %d in, %d out) - response content empty, model may use internal reasoning',
                    $model->getName(),
                    $response->usage->promptTokens,
                    $response->usage->completionTokens,
                );
            }

            return new JsonResponse((new TestConnectionResponse(
                success: true,
                message: $message,
            ))->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new TestConnectionResponse(
                success: false,
                message: $e->getMessage(),
            ))->jsonSerialize());
        }
    }

    /**
     * AJAX: Fetch available model IDs from provider's API.
     *
     * Uses the ModelDiscoveryService to query the provider's API and return
     * a list of available models that can be used in the model_id field.
     */
    public function fetchAvailableModelsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $providerUid = $this->extractIntFromBody($body, 'providerUid');

        if ($providerUid === 0) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No provider UID specified',
            ], 400);
        }

        $provider = $this->providerRepository->findByUid($providerUid);
        if ($provider === null) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Provider not found',
            ], 404);
        }

        try {
            // Create a DetectedProvider to use with ModelDiscoveryService
            $detected = new DetectedProvider(
                adapterType: $provider->getAdapterType(),
                suggestedName: $provider->getName(),
                endpoint: $provider->getEffectiveEndpointUrl(),
            );

            // Discover available models from the provider's API
            $discoveredModels = $this->modelDiscovery->discover(
                $detected,
                $provider->getDecryptedApiKey(),
            );

            // Convert to simple array for JSON response
            $models = [];
            foreach ($discoveredModels as $model) {
                $models[] = [
                    'id' => $model->modelId,
                    'name' => $model->name,
                    'contextLength' => $model->contextLength,
                    'maxOutputTokens' => $model->maxOutputTokens,
                    'capabilities' => $model->capabilities,
                ];
            }

            return new JsonResponse([
                'success' => true,
                'models' => $models,
                'providerName' => $provider->getName(),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * AJAX: Detect model limits by querying the provider's API.
     *
     * Takes a provider UID and model ID, queries the provider's API,
     * and returns the model's context length, max output tokens, and capabilities.
     */
    public function detectLimitsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $providerUid = $this->extractIntFromBody($body, 'providerUid');
        $modelId = $this->extractStringFromBody($body, 'modelId');

        if ($providerUid === 0) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No provider UID specified',
            ], 400);
        }

        if ($modelId === '') {
            return new JsonResponse([
                'success' => false,
                'error' => 'No model ID specified',
            ], 400);
        }

        $provider = $this->providerRepository->findByUid($providerUid);
        if ($provider === null) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Provider not found',
            ], 404);
        }

        try {
            // Create a DetectedProvider to use with ModelDiscovery
            $detected = new DetectedProvider(
                adapterType: $provider->getAdapterType(),
                suggestedName: $provider->getName(),
                endpoint: $provider->getEffectiveEndpointUrl(),
            );

            // Discover available models from the provider's API
            $discoveredModels = $this->modelDiscovery->discover(
                $detected,
                $provider->getDecryptedApiKey(),
            );

            // Find the specific model
            $foundModel = null;
            foreach ($discoveredModels as $model) {
                if ($model->modelId === $modelId) {
                    $foundModel = $model;
                    break;
                }
            }

            if ($foundModel === null) {
                return new JsonResponse([
                    'success' => false,
                    'error' => sprintf('Model "%s" not found in provider\'s available models', $modelId),
                ], 404);
            }

            return new JsonResponse([
                'success' => true,
                'modelId' => $foundModel->modelId,
                'name' => $foundModel->name,
                'description' => $foundModel->description,
                'contextLength' => $foundModel->contextLength,
                'maxOutputTokens' => $foundModel->maxOutputTokens,
                'capabilities' => $foundModel->capabilities,
                'costInput' => $foundModel->costInput,
                'costOutput' => $foundModel->costOutput,
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract model data from request body.
     *
     * @return array<string, mixed>
     */
    private function extractModelData(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $model = $body['model'] ?? [];

        if (!is_array($model)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = $model;

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

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
