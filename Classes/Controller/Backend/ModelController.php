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
use Netresearch\NrLlm\Controller\Backend\Response\ModelListResponse;
use Netresearch\NrLlm\Controller\Backend\Response\SuccessResponse;
use Netresearch\NrLlm\Controller\Backend\Response\ToggleActiveResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
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
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for LLM Model management.
 *
 * Uses TYPO3 FormEngine for record editing (TCA-based forms).
 * Custom actions for AJAX operations (toggle active, test model, etc.).
 */
#[AsController]
final class ModelController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    private const TABLE_NAME = 'tx_nrllm_model';

    private const ERROR_NO_MODEL_UID = 'No model UID specified';

    private const ERROR_MODEL_NOT_FOUND = 'Model not found';

    private const ERROR_NO_PROVIDER_UID = 'No provider UID specified';

    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly ModelRepository $modelRepository,
        private readonly ProviderRepository $providerRepository,
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

        // Build FormEngine URLs for each model
        /** @var array<int, string> $editUrls */
        $editUrls = [];
        foreach ($models as $model) {
            /** @var Model $model */
            $uid = $model->getUid();
            if ($uid === null) {
                continue;
            }
            $editUrls[$uid] = $this->buildEditUrl($uid);
        }

        $period = AnalyticsPeriod::fromPreset('30d', new DateTimeImmutable());
        $usage = UsageAnalyticsService::formatUsageColumns(
            $this->analytics->getTotalsGroupedBy('model_uid', $period->from, $period->to),
        );

        $this->moduleTemplate->assignMultiple([
            'models' => $models,
            'providers' => $providers,
            'capabilities' => Model::getAllCapabilities(),
            'editUrls' => $editUrls,
            'newUrl' => $this->buildNewUrl(),
            'wizardUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_wizard'),
            'hasDefaultModel' => $this->modelRepository->findDefault() !== null,
            'costByModel' => $usage['cost'],
            'reqByModel' => $usage['requests'],
            'tokByModel' => $usage['tokens'],
        ]);

        if (method_exists($this->moduleTemplate->getDocHeaderComponent(), 'setShortcutContext')) {
            $this->moduleTemplate->getDocHeaderComponent()->setShortcutContext(
                routeIdentifier: 'nrllm_models',
                displayName: 'LLM - Models',
            );
        }

        // Add "New Model" button to docheader (links to FormEngine)
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $createButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.model.new', 'NrLlm') ?? 'New Model')
            ->setShowLabelText(true)
            ->setHref($this->buildNewUrl());
        $buttonBar->addButton($createButton);

        return $this->moduleTemplate->renderResponse('Backend/Model/List');
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

        $model = $this->resolveModelOrError($uid);
        if (!$model instanceof Model) {
            return $model;
        }

        try {
            $model->setIsActive(!$model->isActive());
            $this->modelRepository->update($model);
            $this->persistenceManager->persistAll();
            return new JsonResponse((new ToggleActiveResponse(
                success: true,
                isActive: $model->isActive(),
            ))->jsonSerialize());
        } catch (DbalException $e) {
            $this->logger->error('Model toggleActive: persistence failed', ['exception' => $e, 'model_uid' => $uid]);
            $message = 'Database error while toggling model status.';
        } catch (Throwable $e) {
            $this->logger->error('Model toggleActive: unexpected error', ['exception' => $e, 'model_uid' => $uid]);
            $message = 'Failed to toggle model status. See system log for details.';
        }

        return new JsonResponse((new ErrorResponse($message))->jsonSerialize(), 500);
    }

    /**
     * AJAX: Set model as default.
     */
    public function setDefaultAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        $model = $this->resolveModelOrError($uid);
        if (!$model instanceof Model) {
            return $model;
        }

        try {
            $this->modelRepository->setAsDefault($model);
            $this->persistenceManager->persistAll();
            return new JsonResponse((new SuccessResponse())->jsonSerialize());
        } catch (DbalException $e) {
            $this->logger->error('Model setDefault: persistence failed', ['exception' => $e, 'model_uid' => $uid]);
            $message = 'Database error while setting default model.';
        } catch (Throwable $e) {
            $this->logger->error('Model setDefault: unexpected error', ['exception' => $e, 'model_uid' => $uid]);
            $message = 'Failed to set default model. See system log for details.';
        }

        return new JsonResponse((new ErrorResponse($message))->jsonSerialize(), 500);
    }

    /**
     * AJAX: Get models by provider.
     */
    public function getByProviderAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $providerUid = $this->extractIntFromBody($body, 'providerUid');

        if ($providerUid === 0) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.noProviderUid', self::ERROR_NO_PROVIDER_UID)))->jsonSerialize(), 400);
        }

        try {
            $models = $this->modelRepository->findByProviderUid($providerUid);
            return new JsonResponse(ModelListResponse::fromModels($models)->jsonSerialize());
        } catch (DbalException $e) {
            $this->logger->error('Model getByProvider: query failed', ['exception' => $e, 'provider_uid' => $providerUid]);
            $message = 'Database error while loading models.';
        } catch (Throwable $e) {
            $this->logger->error('Model getByProvider: unexpected error', ['exception' => $e, 'provider_uid' => $providerUid]);
            $message = 'Failed to load models. See system log for details.';
        }

        return new JsonResponse((new ErrorResponse($message))->jsonSerialize(), 500);
    }

    /**
     * Build FormEngine edit URL for a model.
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
        return (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_models');
    }

    /**
     * Resolve a model by UID, or return a JSON error response.
     */
    private function resolveModelOrError(int $uid): Model|ResponseInterface
    {
        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.noModelUid', self::ERROR_NO_MODEL_UID)))->jsonSerialize(), 400);
        }

        $model = $this->modelRepository->findByUid($uid);
        if ($model === null) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.notFound', self::ERROR_MODEL_NOT_FOUND)))->jsonSerialize(), 404);
        }

        return $model;
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
