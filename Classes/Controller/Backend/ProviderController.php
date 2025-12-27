<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for LLM Provider management.
 */
#[AsController]
final class ProviderController extends ActionController
{
    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ComponentFactory $componentFactory,
        private readonly IconFactory $iconFactory,
        private readonly ProviderRepository $providerRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    /**
     * List all providers.
     */
    public function listAction(): ResponseInterface
    {
        $providers = $this->providerRepository->findAll();

        $this->moduleTemplate->assignMultiple([
            'providers' => $providers,
            'adapterTypes' => Provider::getAdapterTypes(),
        ]);

        // Add "New Provider" button to docheader
        $createButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.provider.new', 'nr_llm') ?? 'New Provider')
            ->setShowLabelText(true)
            ->setHref((string)$this->uriBuilder->reset()->uriFor('edit'));
        $this->moduleTemplate->addButtonToButtonBar($createButton);

        return $this->moduleTemplate->renderResponse('Backend/Provider/List');
    }

    /**
     * Show edit form for new or existing provider.
     */
    public function editAction(?int $uid = null): ResponseInterface
    {
        $provider = null;
        if ($uid !== null) {
            $provider = $this->providerRepository->findByUid($uid);
            if ($provider === null) {
                $this->addFlashMessage(
                    'Provider not found',
                    'Error',
                    ContextualFeedbackSeverity::ERROR,
                );
                return new RedirectResponse(
                    $this->uriBuilder->reset()->uriFor('list'),
                );
            }
        }

        $this->moduleTemplate->assignMultiple([
            'provider' => $provider,
            'adapterTypes' => Provider::getAdapterTypes(),
            'isNew' => $provider === null,
        ]);

        // Add "Back to List" button to docheader
        $backButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.back', 'nr_llm') ?? 'Back to List')
            ->setShowLabelText(true)
            ->setHref((string)$this->uriBuilder->reset()->uriFor('list'));
        $this->moduleTemplate->addButtonToButtonBar($backButton);

        return $this->moduleTemplate->renderResponse('Backend/Provider/Edit');
    }

    /**
     * Create new provider.
     */
    public function createAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $data = $this->extractProviderData($body);

        $provider = new Provider();
        $this->mapDataToProvider($provider, $data);

        // Validate identifier uniqueness
        if (!$this->providerRepository->isIdentifierUnique($provider->getIdentifier())) {
            $this->addFlashMessage(
                sprintf('Identifier "%s" is already in use', $provider->getIdentifier()),
                'Validation Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('edit'),
            );
        }

        try {
            $this->providerRepository->add($provider);
            $this->persistenceManager->persistAll();
            $this->addFlashMessage(
                sprintf('Provider "%s" created successfully', $provider->getName()),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error creating provider: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list'),
        );
    }

    /**
     * Update existing provider.
     */
    public function updateAction(int $uid): ResponseInterface
    {
        $provider = $this->providerRepository->findByUid($uid);

        if ($provider === null) {
            $this->addFlashMessage(
                'Provider not found',
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list'),
            );
        }

        $body = $this->request->getParsedBody();
        $data = $this->extractProviderData($body);

        // Validate identifier uniqueness (excluding current record)
        $newIdentifier = $data['identifier'] ?? '';
        if (is_string($newIdentifier) && $newIdentifier !== $provider->getIdentifier()
            && !$this->providerRepository->isIdentifierUnique($newIdentifier, $uid)
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

        $this->mapDataToProvider($provider, $data);

        try {
            $this->providerRepository->update($provider);
            $this->persistenceManager->persistAll();
            $this->addFlashMessage(
                sprintf('Provider "%s" updated successfully', $provider->getName()),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error updating provider: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return new RedirectResponse(
            $this->uriBuilder->reset()->uriFor('list'),
        );
    }

    /**
     * Delete provider.
     */
    public function deleteAction(int $uid): ResponseInterface
    {
        $provider = $this->providerRepository->findByUid($uid);

        if ($provider === null) {
            $this->addFlashMessage(
                'Provider not found',
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list'),
            );
        }

        try {
            $name = $provider->getName();
            $this->providerRepository->remove($provider);
            $this->persistenceManager->persistAll();
            $this->addFlashMessage(
                sprintf('Provider "%s" deleted successfully', $name),
                'Success',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                'Error deleting provider: ' . $e->getMessage(),
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
            return new JsonResponse(['error' => 'No provider UID specified'], 400);
        }

        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null) {
            return new JsonResponse(['error' => 'Provider not found'], 404);
        }

        try {
            $provider->setIsActive(!$provider->isActive());
            $this->providerRepository->update($provider);
            $this->persistenceManager->persistAll();
            return new JsonResponse([
                'success' => true,
                'isActive' => $provider->isActive(),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Test provider connection.
     */
    public function testConnectionAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse(['error' => 'No provider UID specified'], 400);
        }

        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null) {
            return new JsonResponse(['error' => 'Provider not found'], 404);
        }

        try {
            // TODO: Implement actual connection test via ProviderAdapterRegistry
            // For now, just check if API key is configured
            if (!$provider->hasApiKey()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'API key not configured',
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Connection test not yet implemented',
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract provider data from request body.
     *
     * @return array<string, mixed>
     */
    private function extractProviderData(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $provider = $body['provider'] ?? [];

        if (!is_array($provider)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = $provider;

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
     * Map form data to provider entity.
     *
     * @param array<string, mixed> $data
     */
    private function mapDataToProvider(Provider $provider, array $data): void
    {
        if (isset($data['identifier']) && is_scalar($data['identifier'])) {
            $provider->setIdentifier((string)$data['identifier']);
        }
        if (isset($data['name']) && is_scalar($data['name'])) {
            $provider->setName((string)$data['name']);
        }
        if (isset($data['description']) && is_scalar($data['description'])) {
            $provider->setDescription((string)$data['description']);
        }
        if (isset($data['adapterType']) && is_scalar($data['adapterType'])) {
            $provider->setAdapterType((string)$data['adapterType']);
        }
        if (isset($data['endpointUrl']) && is_scalar($data['endpointUrl'])) {
            $provider->setEndpointUrl((string)$data['endpointUrl']);
        }
        if (isset($data['apiKey']) && is_scalar($data['apiKey'])) {
            $provider->setApiKey((string)$data['apiKey']);
        }
        if (isset($data['organizationId']) && is_scalar($data['organizationId'])) {
            $provider->setOrganizationId((string)$data['organizationId']);
        }
        if (isset($data['timeout']) && is_numeric($data['timeout'])) {
            $provider->setTimeout((int)$data['timeout']);
        }
        if (isset($data['maxRetries']) && is_numeric($data['maxRetries'])) {
            $provider->setMaxRetries((int)$data['maxRetries']);
        }
        if (isset($data['options']) && is_scalar($data['options'])) {
            $provider->setOptions((string)$data['options']);
        }
        if (isset($data['isActive'])) {
            $provider->setIsActive((bool)$data['isActive']);
        }
    }
}
