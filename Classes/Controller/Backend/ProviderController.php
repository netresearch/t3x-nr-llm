<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConnectionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Analytics\AnalyticsPeriod;
use Netresearch\NrLlm\Service\UsageAnalyticsService;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
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
 * Backend controller for LLM Provider management.
 *
 * Uses TYPO3 FormEngine for record editing (TCA-based forms).
 * Custom actions for AJAX operations (toggle active, test connection).
 */
#[AsController]
final class ProviderController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    private const TABLE_NAME = 'tx_nrllm_provider';

    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly ProviderRepository $providerRepository,
        private readonly ProviderAdapterRegistryInterface $providerAdapterRegistry,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly UsageAnalyticsServiceInterface $analytics,
        private readonly LoggerInterface $logger,
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

        // Build FormEngine URLs for each provider
        /** @var array<int, string> $editUrls */
        $editUrls = [];
        foreach ($providers as $provider) {
            /** @var Provider $provider */
            $uid = $provider->getUid();
            if ($uid === null) {
                continue;
            }
            $editUrls[$uid] = $this->buildEditUrl($uid);
        }

        $period = AnalyticsPeriod::fromPreset('30d', new DateTimeImmutable());
        $usage = UsageAnalyticsService::formatUsageColumns(
            $this->analytics->getTotalsGroupedBy('service_provider', $period->from, $period->to),
        );

        $this->moduleTemplate->assignMultiple([
            'providers' => $providers,
            'adapterTypes' => Provider::getAdapterTypes(),
            'editUrls' => $editUrls,
            'newUrl' => $this->buildNewUrl(),
            'wizardUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_wizard'),
            'costByProvider' => $usage['cost'],
            'reqByProvider' => $usage['requests'],
            'tokByProvider' => $usage['tokens'],
        ]);

        if (method_exists($this->moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm_providers',
                displayName: 'LLM - Providers',
            );
        }

        // Add "New Provider" button to docheader (links to FormEngine)
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $createButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.provider.new', 'New Provider'))
            ->setShowLabelText(true)
            ->setHref($this->buildNewUrl());
        $buttonBar->addButton($createButton);

        return $this->moduleTemplate->renderResponse('Backend/Provider/List');
    }

    /**
     * AJAX: Toggle active status.
     */
    public function toggleActiveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.noUid', 'No provider UID specified')))->jsonSerialize(), 400);
        }

        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.notFound', 'Provider not found')))->jsonSerialize(), 404);
        }

        try {
            $provider->setIsActive(!$provider->isActive());
            $this->providerRepository->update($provider);
            $this->persistenceManager->persistAll();
            $response = new JsonResponse((new ToggleActiveResponse(
                success: true,
                isActive: $provider->isActive(),
            ))->jsonSerialize());
        } catch (DbalException $e) {
            // REC #8b: SQL error text → log, surface generic message.
            $this->logger->error('Provider toggleActive: persistence failed', [
                'exception'    => $e,
                'provider_uid' => $uid,
            ]);
            $response = new JsonResponse(
                (new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.toggleDbError', 'Database error while toggling provider status.')))->jsonSerialize(),
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error('Provider toggleActive: unexpected error', [
                'exception'    => $e,
                'provider_uid' => $uid,
            ]);
            $response = new JsonResponse(
                (new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.toggleFailed', 'Failed to toggle provider status. See system log for details.')))->jsonSerialize(),
                500,
            );
        }

        return $response;
    }

    /**
     * AJAX: Test provider connection.
     */
    public function testConnectionAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.noUid', 'No provider UID specified')))->jsonSerialize(), 400);
        }

        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.notFound', 'Provider not found')))->jsonSerialize(), 404);
        }

        try {
            $result = $this->providerAdapterRegistry->testProviderConnection($provider);

            $response = new JsonResponse(TestConnectionResponse::fromResult($result)->jsonSerialize());
        } catch (ProviderResponseException $e) {
            // REC #8b: provider response bodies often reference upstream
            // endpoints / model names — log full detail, surface generic.
            $this->logger->error('Provider testConnection: provider returned an error', [
                'exception'    => $e,
                'http_status'  => $e->httpStatus,
                'endpoint'     => $e->endpoint,
                'provider_uid' => $uid,
            ]);
            $response = new JsonResponse(
                (new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.testUpstreamError', 'Upstream LLM provider returned an error during connection test.')))->jsonSerialize(),
                502,
            );
        } catch (ProviderException $e) {
            $this->logger->error('Provider testConnection: provider error', [
                'exception'    => $e,
                'provider_uid' => $uid,
            ]);
            $response = new JsonResponse(
                (new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.testProviderError', 'LLM provider error during connection test. See system log for details.')))->jsonSerialize(),
                502,
            );
        } catch (Throwable $e) {
            $this->logger->error('Provider testConnection: unexpected error', [
                'exception'    => $e,
                'provider_uid' => $uid,
            ]);
            $response = new JsonResponse(
                (new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.testFailed', 'Connection test failed. See system log for details.')))->jsonSerialize(),
                500,
            );
        }

        return $response;
    }

    /**
     * Build FormEngine edit URL for a provider.
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
        return (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_providers');
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
}
