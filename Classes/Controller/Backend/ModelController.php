<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ModelListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\SuccessResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConnectionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
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
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        // Register AJAX URLs for JavaScript
        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_model_toggle_active' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_model_toggle_active'),
            'nrllm_model_set_default' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_model_set_default'),
            'nrllm_model_test' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_model_test'),
        ]);

        // Load JavaScript for model list actions
        $this->pageRenderer->addJsFile(
            'EXT:nr_llm/Resources/Public/JavaScript/Backend/ModelList.js',
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
     * List all models.
     */
    public function listAction(): ResponseInterface
    {
        $models = $this->modelRepository->findAll();
        $providers = $this->providerRepository->findActive();

        // Build provider lookup map and populate provider relation on models
        $providerMap = [];
        foreach ($providers as $provider) {
            $uid = $provider->getUid();
            if ($uid !== null) {
                $providerMap[$uid] = $provider;
            }
        }
        foreach ($models as $model) {
            if (!$model instanceof Model) {
                continue;
            }
            if ($model->getProviderUid() > 0) {
                $model->setProvider($providerMap[$model->getProviderUid()] ?? null);
            }
        }

        $this->moduleTemplate->assignMultiple([
            'models' => $models,
            'providers' => $providers,
            'capabilities' => Model::getAllCapabilities(),
        ]);

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

        $this->moduleTemplate->assignMultiple([
            'model' => $model,
            'providers' => $this->providerRepository->findActive(),
            'capabilities' => Model::getAllCapabilities(),
            'isNew' => $model === null,
        ]);

        // Add "Back to List" button to docheader
        $backButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.back', 'NrLlm') ?? 'Back to List')
            ->setShowLabelText(true)
            ->setHref((string)$this->uriBuilder->reset()->uriFor('list'));
        $this->moduleTemplate->addButtonToButtonBar($backButton);

        return $this->moduleTemplate->renderResponse('Backend/Model/Edit');
    }

    /**
     * Create new model.
     */
    public function createAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $data = $this->extractModelData($body);

        $model = new Model();
        $this->mapDataToModel($model, $data);

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

        // Validate provider exists
        if ($model->getProviderUid() > 0) {
            $provider = $this->providerRepository->findByUid($model->getProviderUid());
            if ($provider !== null) {
                $model->setProvider($provider);
            }
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

        // Validate identifier uniqueness (excluding current record)
        $newIdentifier = $data['identifier'] ?? '';
        if (is_string($newIdentifier) && $newIdentifier !== $model->getIdentifier()
            && !$this->modelRepository->isIdentifierUnique($newIdentifier, $uid)
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

        $this->mapDataToModel($model, $data);

        // Update provider relation
        if ($model->getProviderUid() > 0) {
            $provider = $this->providerRepository->findByUid($model->getProviderUid());
            if ($provider !== null) {
                $model->setProvider($provider);
            }
        }

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

        // Ensure we have the Provider entity loaded
        if ($model->getProviderUid() > 0 && $model->getProvider() === null) {
            $provider = $this->providerRepository->findByUid($model->getProviderUid());
            if ($provider !== null) {
                $model->setProvider($provider);
            }
        }

        if ($model->getProvider() === null) {
            return new JsonResponse((new ErrorResponse('Model has no provider configured'))->jsonSerialize(), 400);
        }

        try {
            // Create adapter from model's provider
            $adapter = $this->providerAdapterRegistry->createAdapterFromModel($model);

            // Make a simple test call
            $testPrompt = 'Say "OK" to confirm this model is working.';
            $response = $adapter->complete($testPrompt, [
                'model' => $model->getModelId(),
                'max_tokens' => 10,
                'temperature' => 0.0,
            ]);

            $responseText = trim($response->content);
            $message = sprintf(
                'Model "%s" responded: "%s" (tokens: %d in, %d out)',
                $model->getName(),
                mb_substr($responseText, 0, 50) . (mb_strlen($responseText) > 50 ? '...' : ''),
                $response->usage->promptTokens,
                $response->usage->completionTokens,
            );

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
     * Map form data to model entity.
     *
     * @param array<string, mixed> $data
     */
    private function mapDataToModel(Model $model, array $data): void
    {
        if (isset($data['identifier']) && is_scalar($data['identifier'])) {
            $model->setIdentifier((string)$data['identifier']);
        }
        if (isset($data['name']) && is_scalar($data['name'])) {
            $model->setName((string)$data['name']);
        }
        if (isset($data['description']) && is_scalar($data['description'])) {
            $model->setDescription((string)$data['description']);
        }
        if (isset($data['providerUid']) && is_numeric($data['providerUid'])) {
            $model->setProviderUid((int)$data['providerUid']);
        }
        if (isset($data['modelId']) && is_scalar($data['modelId'])) {
            $model->setModelId((string)$data['modelId']);
        }
        if (isset($data['contextLength']) && is_numeric($data['contextLength'])) {
            $model->setContextLength((int)$data['contextLength']);
        }
        if (isset($data['maxOutputTokens']) && is_numeric($data['maxOutputTokens'])) {
            $model->setMaxOutputTokens((int)$data['maxOutputTokens']);
        }
        if (isset($data['capabilities'])) {
            if (is_array($data['capabilities'])) {
                /** @var array<string> $capabilities */
                $capabilities = array_filter($data['capabilities'], is_string(...));
                $model->setCapabilitiesArray($capabilities);
            } elseif (is_string($data['capabilities'])) {
                $model->setCapabilities($data['capabilities']);
            }
        }
        if (isset($data['costInput']) && is_numeric($data['costInput'])) {
            $model->setCostInput((int)$data['costInput']);
        }
        if (isset($data['costOutput']) && is_numeric($data['costOutput'])) {
            $model->setCostOutput((int)$data['costOutput']);
        }
        if (isset($data['isActive'])) {
            $model->setIsActive((bool)$data['isActive']);
        }
        if (isset($data['isDefault'])) {
            $model->setIsDefault((bool)$data['isDefault']);
        }
    }
}
