<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
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
        private readonly LlmServiceManager $llmServiceManager,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    /**
     * List all LLM configurations.
     */
    public function listAction(): ResponseInterface
    {
        $configurations = $this->configurationRepository->findAll();

        $this->moduleTemplate->assignMultiple([
            'configurations' => $configurations,
            'providers' => $this->getProviderOptions(),
        ]);

        // Add "New Configuration" button to docheader
        $createButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.configuration.new', 'nr_llm') ?? 'New Configuration')
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

        $this->moduleTemplate->assignMultiple([
            'configuration' => $configuration,
            'models' => $this->modelRepository->findActive(),
            'isNew' => $configuration === null,
        ]);

        // Add "Back to List" button to docheader
        $backButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.back', 'nr_llm') ?? 'Back to List')
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
    public function toggleActiveAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse(['error' => 'No configuration UID specified'], 400);
        }

        $configuration = $this->configurationRepository->findByUid($uid);
        if ($configuration === null) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        try {
            $this->configurationService->toggleActive($configuration);
            return new JsonResponse([
                'success' => true,
                'isActive' => $configuration->isActive(),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Set configuration as default.
     */
    public function setDefaultAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse(['error' => 'No configuration UID specified'], 400);
        }

        $configuration = $this->configurationRepository->findByUid($uid);
        if ($configuration === null) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        try {
            $this->configurationService->setAsDefault($configuration);
            return new JsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Get available models for a provider.
     */
    public function getModelsAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $providerKey = $this->extractStringFromBody($body, 'provider');

        if ($providerKey === '') {
            return new JsonResponse(['error' => 'No provider specified'], 400);
        }

        try {
            $providers = $this->llmServiceManager->getAvailableProviders();
            if (!isset($providers[$providerKey])) {
                return new JsonResponse(['error' => 'Provider not available'], 404);
            }

            $provider = $providers[$providerKey];
            $models = $provider->getAvailableModels();
            $defaultModel = $provider->getDefaultModel();

            return new JsonResponse([
                'success' => true,
                'models' => $models,
                'defaultModel' => $defaultModel,
            ]);
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Test configuration.
     */
    public function testConfigurationAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse(['error' => 'No configuration UID specified'], 400);
        }

        $configuration = $this->configurationRepository->findByUid($uid);
        if ($configuration === null) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        try {
            $chatOptions = $configuration->toChatOptions();
            $response = $this->llmServiceManager->complete(
                'Hello, please respond with a brief greeting.',
                $chatOptions,
            );

            return new JsonResponse([
                'success' => true,
                'content' => $response->content,
                'model' => $response->model,
                'usage' => [
                    'promptTokens' => $response->usage->promptTokens,
                    'completionTokens' => $response->usage->completionTokens,
                    'totalTokens' => $response->usage->totalTokens,
                ],
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
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
