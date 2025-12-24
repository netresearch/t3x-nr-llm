<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend controller for LLM configuration management
 */
#[AsController]
final class ConfigurationController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LlmConfigurationService $configurationService,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly LlmServiceManager $llmServiceManager,
    ) {}

    /**
     * List all LLM configurations
     */
    public function listAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $configurations = $this->configurationRepository->findAll();

        $moduleTemplate->assignMultiple([
            'configurations' => $configurations,
            'providers' => $this->getProviderOptions(),
        ]);

        return $moduleTemplate->renderResponse('Backend/Configuration/List');
    }

    /**
     * Show edit form for new or existing configuration
     */
    public function editAction(?int $uid = null): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $configuration = null;
        if ($uid !== null) {
            $configuration = $this->configurationRepository->findByUid($uid);
            if ($configuration === null) {
                $this->addFlashMessage(
                    'Configuration not found',
                    'Error',
                    ContextualFeedbackSeverity::ERROR
                );
                return new RedirectResponse(
                    $this->uriBuilder->reset()->uriFor('list')
                );
            }
        }

        $moduleTemplate->assignMultiple([
            'configuration' => $configuration,
            'providers' => $this->getProviderOptions(),
            'isNew' => $configuration === null,
        ]);

        return $moduleTemplate->renderResponse('Backend/Configuration/Edit');
    }

    /**
     * Create new configuration
     */
    public function createAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $data = $body['configuration'] ?? [];

        $configuration = new LlmConfiguration();
        $this->mapDataToConfiguration($configuration, $data);

        // Validate identifier uniqueness
        if (!$this->configurationService->isIdentifierAvailable($configuration->getIdentifier())) {
            $this->addFlashMessage(
                sprintf('Identifier "%s" is already in use', $configuration->getIdentifier()),
                'Validation Error',
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('edit')
            );
        }

        try {
            $this->configurationService->create($configuration);
            $this->addFlashMessage(
                sprintf('Configuration "%s" created successfully', $configuration->getName()),
                'Success',
                ContextualFeedbackSeverity::OK
            );
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                'Error creating configuration: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list')
        );
    }

    /**
     * Update existing configuration
     */
    public function updateAction(int $uid): ResponseInterface
    {
        $configuration = $this->configurationRepository->findByUid($uid);

        if ($configuration === null) {
            $this->addFlashMessage(
                'Configuration not found',
                'Error',
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list')
            );
        }

        $body = $this->request->getParsedBody();
        $data = $body['configuration'] ?? [];

        // Validate identifier uniqueness (excluding current record)
        $newIdentifier = $data['identifier'] ?? '';
        if ($newIdentifier !== $configuration->getIdentifier()
            && !$this->configurationService->isIdentifierAvailable($newIdentifier, $uid)
        ) {
            $this->addFlashMessage(
                sprintf('Identifier "%s" is already in use', $newIdentifier),
                'Validation Error',
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('edit', ['uid' => $uid])
            );
        }

        $this->mapDataToConfiguration($configuration, $data);

        try {
            $this->configurationService->update($configuration);
            $this->addFlashMessage(
                sprintf('Configuration "%s" updated successfully', $configuration->getName()),
                'Success',
                ContextualFeedbackSeverity::OK
            );
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                'Error updating configuration: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list')
        );
    }

    /**
     * Delete configuration
     */
    public function deleteAction(int $uid): ResponseInterface
    {
        $configuration = $this->configurationRepository->findByUid($uid);

        if ($configuration === null) {
            $this->addFlashMessage(
                'Configuration not found',
                'Error',
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list')
            );
        }

        try {
            $name = $configuration->getName();
            $this->configurationService->delete($configuration);
            $this->addFlashMessage(
                sprintf('Configuration "%s" deleted successfully', $name),
                'Success',
                ContextualFeedbackSeverity::OK
            );
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                'Error deleting configuration: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list')
        );
    }

    /**
     * AJAX: Toggle active status
     */
    public function toggleActiveAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $uid = (int) ($body['uid'] ?? 0);

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
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Set configuration as default
     */
    public function setDefaultAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $uid = (int) ($body['uid'] ?? 0);

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
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Get available models for a provider
     */
    public function getModelsAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $provider = $body['provider'] ?? null;

        if (!$provider) {
            return new JsonResponse(['error' => 'No provider specified'], 400);
        }

        try {
            $providers = $this->llmServiceManager->getAvailableProviders();
            if (!isset($providers[$provider])) {
                return new JsonResponse(['error' => 'Provider not available'], 404);
            }

            $models = $providers[$provider]->getAvailableModels();
            $defaultModel = $providers[$provider]->getDefaultModel();

            return new JsonResponse([
                'success' => true,
                'models' => $models,
                'defaultModel' => $defaultModel,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Test configuration
     */
    public function testConfigurationAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $uid = (int) ($body['uid'] ?? 0);

        if ($uid === 0) {
            return new JsonResponse(['error' => 'No configuration UID specified'], 400);
        }

        $configuration = $this->configurationRepository->findByUid($uid);
        if ($configuration === null) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        try {
            $options = $configuration->toOptionsArray();
            $response = $this->llmServiceManager->complete(
                'Hello, please respond with a brief greeting.',
                $options
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
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get provider options for select field
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
     * Map form data to configuration entity
     *
     * @param array<string, mixed> $data
     */
    private function mapDataToConfiguration(LlmConfiguration $configuration, array $data): void
    {
        if (isset($data['identifier'])) {
            $configuration->setIdentifier((string) $data['identifier']);
        }
        if (isset($data['name'])) {
            $configuration->setName((string) $data['name']);
        }
        if (isset($data['description'])) {
            $configuration->setDescription((string) $data['description']);
        }
        if (isset($data['provider'])) {
            $configuration->setProvider((string) $data['provider']);
        }
        if (isset($data['model'])) {
            $configuration->setModel((string) $data['model']);
        }
        if (isset($data['systemPrompt'])) {
            $configuration->setSystemPrompt((string) $data['systemPrompt']);
        }
        if (isset($data['temperature'])) {
            $configuration->setTemperature((float) $data['temperature']);
        }
        if (isset($data['maxTokens'])) {
            $configuration->setMaxTokens((int) $data['maxTokens']);
        }
        if (isset($data['topP'])) {
            $configuration->setTopP((float) $data['topP']);
        }
        if (isset($data['frequencyPenalty'])) {
            $configuration->setFrequencyPenalty((float) $data['frequencyPenalty']);
        }
        if (isset($data['presencePenalty'])) {
            $configuration->setPresencePenalty((float) $data['presencePenalty']);
        }
        if (isset($data['options'])) {
            $configuration->setOptions((string) $data['options']);
        }
        if (isset($data['maxRequestsPerDay'])) {
            $configuration->setMaxRequestsPerDay((int) $data['maxRequestsPerDay']);
        }
        if (isset($data['maxTokensPerDay'])) {
            $configuration->setMaxTokensPerDay((int) $data['maxTokensPerDay']);
        }
        if (isset($data['maxCostPerDay'])) {
            $configuration->setMaxCostPerDay((float) $data['maxCostPerDay']);
        }
        if (isset($data['isActive'])) {
            $configuration->setIsActive((bool) $data['isActive']);
        }
        if (isset($data['isDefault'])) {
            $configuration->setIsDefault((bool) $data['isDefault']);
        }
    }
}
