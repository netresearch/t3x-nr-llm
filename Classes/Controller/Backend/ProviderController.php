<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConnectionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Domain\Model\Provider;
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
        private readonly ProviderAdapterRegistry $providerAdapterRegistry,
        private readonly PersistenceManagerInterface $persistenceManager,
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
            'nrllm_provider_toggle_active' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_provider_toggle_active'),
            'nrllm_provider_test_connection' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_provider_test_connection'),
        ]);

        // Load JavaScript for provider list actions (ES6 module)
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ProviderList.js');
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

        // Add shortcut/bookmark button to docheader
        $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            routeIdentifier: 'nrllm_providers',
            displayName: 'LLM - Providers',
        );

        // Add "New Provider" button to docheader
        $createButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.provider.new', 'NrLlm') ?? 'New Provider')
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

        // Add shortcut/bookmark button to docheader
        $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            routeIdentifier: 'nrllm_providers',
            displayName: $provider !== null
                ? sprintf('LLM - Provider: %s', $provider->getName())
                : 'LLM - New Provider',
            arguments: $provider !== null ? ['uid' => $provider->getUid()] : [],
        );

        // Add "Back to List" button to docheader
        $backButton = $this->componentFactory->createLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.back', 'NrLlm') ?? 'Back to List')
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
    public function toggleActiveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse('No provider UID specified'))->jsonSerialize(), 400);
        }

        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null) {
            return new JsonResponse((new ErrorResponse('Provider not found'))->jsonSerialize(), 404);
        }

        try {
            $provider->setIsActive(!$provider->isActive());
            $this->providerRepository->update($provider);
            $this->persistenceManager->persistAll();
            return new JsonResponse((new ToggleActiveResponse(
                success: true,
                isActive: $provider->isActive(),
            ))->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
        }
    }

    /**
     * AJAX: Test provider connection.
     */
    public function testConnectionAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse('No provider UID specified'))->jsonSerialize(), 400);
        }

        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null) {
            return new JsonResponse((new ErrorResponse('Provider not found'))->jsonSerialize(), 404);
        }

        try {
            $result = $this->providerAdapterRegistry->testProviderConnection($provider);

            return new JsonResponse(TestConnectionResponse::fromResult($result)->jsonSerialize());
        } catch (Throwable $e) {
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize(), 500);
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
