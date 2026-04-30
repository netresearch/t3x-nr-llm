<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\DTO\ExecuteTaskRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\RefreshInputRequest;
use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TaskExecutionResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TaskInputResponse;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Task\TaskExecutionServiceInterface;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for the Task execution pathway.
 *
 * Slice 13e of the `TaskController` split (ADR-027). Owns three
 * actions:
 *
 * - `executeFormAction(int $uid)` — render the execute form with
 *   resolved input data, the effective configuration, and edit URLs
 *   for the full Task → Configuration → Model → Provider chain.
 * - `executeAction(ServerRequestInterface $request)` — AJAX entry
 *   point that delegates to `TaskExecutionService` and returns a
 *   typed `TaskExecutionResponse` (or `ErrorResponse` on failure).
 * - `refreshInputAction(ServerRequestInterface $request)` — re-resolve
 *   the input source for an already-rendered execute form.
 *
 * Constructor scope is narrow: only the dependencies these three
 * actions actually use.
 */
#[AsController]
final class TaskExecutionController extends ActionController
{
    private const TABLE_NAME = 'tx_nrllm_task';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly TaskRepository $taskRepository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly TaskExecutionServiceInterface $taskExecutionService,
        private readonly TaskInputResolverInterface $taskInputResolver,
        private readonly FlashMessageService $flashMessageService,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    /**
     * Show execute form for a task.
     */
    public function executeFormAction(int $uid): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $task = $this->taskRepository->findByUid($uid);
        if ($task === null) {
            $this->enqueueFlashMessage(
                LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.notFound', 'NrLlm') ?? 'Task not found.',
                LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.error', 'NrLlm') ?? 'Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('list', [], 'TaskList'));
        }

        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_task_list_tables'      => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_list_tables'),
            'nrllm_task_fetch_records'    => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_fetch_records'),
            'nrllm_task_load_record_data' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_load_record_data'),
            'nrllm_task_refresh_input'    => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_refresh_input'),
            'nrllm_task_execute'          => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_execute'),
        ]);

        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/TaskExecute.js');

        $inputData = $this->taskInputResolver->resolve($task);

        // Resolve the effective configuration (task's own or system default).
        $configuration = $task->getConfiguration();
        $isUsingDefault = false;
        if ($configuration === null) {
            $configuration = $this->configurationRepository->findDefault();
            $isUsingDefault = true;
        }

        // Return URL for FormEngine: back to this specific task's execute form.
        $executeReturnUrl = (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_tasks', [
            'tx_nrllm_task[action]' => 'executeForm',
            'tx_nrllm_task[uid]'    => $task->getUid(),
        ]);

        // Build edit URLs for the full chain: task → configuration → model → provider.
        $taskEditUrl = $task->getUid() !== null
            ? (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit'      => [self::TABLE_NAME => [$task->getUid() => 'edit']],
                'returnUrl' => $executeReturnUrl,
            ])
            : null;

        $configEditUrl = null;
        $modelEditUrl = null;
        $providerEditUrl = null;

        if ($configuration !== null && $configuration->getUid() !== null) {
            $configEditUrl = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit'      => ['tx_nrllm_configuration' => [$configuration->getUid() => 'edit']],
                'returnUrl' => $executeReturnUrl,
            ]);

            $model = $configuration->getLlmModel();
            if ($model !== null && $model->getUid() !== null) {
                $modelEditUrl = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                    'edit'      => ['tx_nrllm_model' => [$model->getUid() => 'edit']],
                    'returnUrl' => $executeReturnUrl,
                ]);

                $provider = $model->getProvider();
                if ($provider !== null && $provider->getUid() !== null) {
                    $providerEditUrl = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                        'edit'      => ['tx_nrllm_provider' => [$provider->getUid() => 'edit']],
                        'returnUrl' => $executeReturnUrl,
                    ]);
                }
            }
        }

        $moduleTemplate->assignMultiple([
            'task'                 => $task,
            'inputData'            => $inputData,
            'requiresManualInput'  => $task->requiresManualInput(),
            'effectiveConfig'      => $configuration,
            'isUsingDefaultConfig' => $isUsingDefault,
            'configEditUrl'        => $configEditUrl,
            'modelEditUrl'         => $modelEditUrl,
            'providerEditUrl'      => $providerEditUrl,
            'taskEditUrl'          => $taskEditUrl,
        ]);

        return $moduleTemplate->renderResponse('Backend/Task/Execute');
    }

    /**
     * Execute a task via AJAX.
     */
    public function executeAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = ExecuteTaskRequest::fromRequest($request);

        $task = $this->taskRepository->findByUid($dto->uid);
        if ($task === null) {
            return new JsonResponse((new ErrorResponse('Task not found'))->jsonSerialize(), 404);
        }

        if (!$task->isActive()) {
            return new JsonResponse((new ErrorResponse('Task is not active'))->jsonSerialize(), 400);
        }

        try {
            $result = $this->taskExecutionService->execute($task, $dto->input);
        } catch (Throwable $e) {
            // Return 200 with success:false so JavaScript can read the error message —
            // HTTP 500 makes TYPO3's AjaxRequest throw before parsing the JSON.
            return new JsonResponse((new ErrorResponse($e->getMessage()))->jsonSerialize());
        }

        return new JsonResponse(TaskExecutionResponse::fromResult($result)->jsonSerialize());
    }

    /**
     * Refresh input data for a task via AJAX.
     */
    public function refreshInputAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = RefreshInputRequest::fromRequest($request);

        $task = $this->taskRepository->findByUid($dto->uid);
        if ($task === null) {
            return new JsonResponse((new ErrorResponse('Task not found'))->jsonSerialize(), 404);
        }

        $inputData = $this->taskInputResolver->resolve($task);

        // Empty when the resolver returned the empty string OR the
        // localized "no deprecation log file found" placeholder. Compare
        // against the *translated* string so non-English backends don't
        // see `isEmpty=false` for what is really a missing log.
        $deprecationNotFound = LocalizationUtility::translate(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.deprecationLog.notFound',
            'NrLlm',
        ) ?? 'No deprecation log file found.';

        return new JsonResponse((new TaskInputResponse(
            inputData: $inputData,
            inputType: $task->getInputType(),
            isEmpty: $inputData === '' || $inputData === $deprecationNotFound,
        ))->jsonSerialize());
    }

    private function enqueueFlashMessage(
        string $message,
        string $title,
        ContextualFeedbackSeverity $severity,
    ): void {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $title,
            $severity,
            true,
        );
        $this->flashMessageService
            ->getMessageQueueByIdentifier()
            ->addMessage($flashMessage);
    }
}
