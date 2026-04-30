<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Countable;
use Netresearch\NrLlm\Controller\Backend\DTO\ExecuteTaskRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\FetchRecordsRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\LoadRecordDataRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\RefreshInputRequest;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Task\DeprecationLogReaderInterface;
use Netresearch\NrLlm\Service\Task\RecordTableReaderInterface;
use Netresearch\NrLlm\Service\Task\SystemLogReaderInterface;
use Netresearch\NrLlm\Service\WizardGeneratorService;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for managing one-shot prompt tasks.
 *
 * Uses TYPO3 FormEngine for record editing (TCA-based forms).
 * Custom actions for task execution and AJAX operations.
 *
 * Tasks are simple, predefined prompts for common operations.
 * They are NOT AI agents - they cannot perform multi-step reasoning,
 * use tools, or maintain conversation context.
 */
#[AsController]
final class TaskController extends ActionController
{
    use SafeCastTrait;

    private const TABLE_NAME = 'tx_nrllm_task';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly TaskRepository $taskRepository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly ModelRepository $modelRepository,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly WizardGeneratorService $wizardGeneratorService,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly FlashMessageService $flashMessageService,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly RecordTableReaderInterface $recordTableReader,
        private readonly SystemLogReaderInterface $systemLogReader,
        private readonly DeprecationLogReaderInterface $deprecationLogReader,
    ) {}

    /**
     * List all tasks grouped by category.
     */
    public function listAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        /** @var QueryResultInterface<int, Task>&Countable $tasks */
        $tasks = $this->taskRepository->findAll();
        $categories = Task::getCategories();

        // Group tasks by category
        $groupedTasks = [];
        foreach ($categories as $categoryKey => $categoryLabel) {
            $groupedTasks[$categoryKey] = [
                'label' => $categoryLabel,
                'tasks' => [],
            ];
        }

        // Build FormEngine URLs for each task
        /** @var array<int, string> $editUrls */
        $editUrls = [];
        foreach ($tasks as $task) {
            if (!$task instanceof Task) { // @phpstan-ignore instanceof.alwaysTrue
                continue;
            }
            $uid = $task->getUid();
            if ($uid === null) {
                continue;
            }
            $editUrls[$uid] = $this->buildEditUrl($uid);
            $category = $task->getCategory();
            if (!isset($groupedTasks[$category])) {
                $groupedTasks[$category] = [
                    'label' => $category,
                    'tasks' => [],
                ];
            }
            $groupedTasks[$category]['tasks'][] = $task;
        }

        // Remove empty categories
        $groupedTasks = array_filter($groupedTasks, fn($group) => !empty($group['tasks']));

        $moduleTemplate->assignMultiple([
            'groupedTasks' => $groupedTasks,
            'totalCount' => $tasks->count(),
            'editUrls' => $editUrls,
            'newUrl' => $this->buildNewUrl(),
            'wizardUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_wizard'),
        ]);

        // Add "New Task" and "Create with AI" buttons to docheader
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $createButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle('New Task')
            ->setShowLabelText(true)
            ->setHref($this->buildNewUrl());
        $buttonBar->addButton($createButton);

        $aiButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-bolt', IconSize::SMALL))
            ->setTitle('Create with AI')
            ->setShowLabelText(true)
            ->setHref($this->uriBuilder->reset()->uriFor('wizardForm'));
        $buttonBar->addButton($aiButton, ButtonBar::BUTTON_POSITION_LEFT, 2);

        return $moduleTemplate->renderResponse('Backend/Task/List');
    }

    /**
     * Show the "Create with AI" wizard form for tasks.
     */
    public function wizardFormAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/WizardFormLoading.js');

        $availableConfigs = $this->configurationRepository->findActive();
        $resolvedConfig = $this->wizardGeneratorService->resolveConfiguration();

        $moduleTemplate->assignMultiple([
            'wizardType' => 'task',
            'availableConfigs' => $availableConfigs,
            'resolvedConfig' => $resolvedConfig,
        ]);
        return $moduleTemplate->renderResponse('Backend/Task/WizardForm');
    }

    /**
     * Generate task from description and show preview.
     */
    public function wizardGenerateAction(string $description = '', int $configurationUid = 0): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $description = trim($description);
        if ($description === '') {
            $this->enqueueFlashMessage('Please describe what this task should do.', 'Missing description', ContextualFeedbackSeverity::WARNING);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
        if (mb_strlen($description) > 2000) {
            $description = mb_substr($description, 0, 2000);
        }

        try {
            $config = $this->wizardGeneratorService->resolveConfiguration(
                $configurationUid > 0 ? $configurationUid : null,
            );
            $result = $this->wizardGeneratorService->generateTask($description, $config);
            $table = 'tx_nrllm_task';
            $params = [
                'edit' => [$table => [0 => 'new']],
                'returnUrl' => $this->buildReturnUrl(),
            ];
            foreach ($result as $key => $value) {
                if ($value !== '' && $value !== null && $key !== 'generated' && $key !== 'recommended_model') {
                    $params['defVals[' . $table . '][' . $key . ']'] = is_string($value) || is_numeric($value) ? (string)$value : '';
                }
            }
            $newUrl = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', $params);

            $moduleTemplate->assignMultiple([
                'wizardType' => 'task',
                'description' => $description,
                'generated' => $result,
                'newUrl' => $newUrl,
                'usedConfig' => $config,
                'configurationUid' => $configurationUid,
            ]);
            return $moduleTemplate->renderResponse('Backend/Task/WizardPreview');
        } catch (Throwable $e) {
            $this->enqueueFlashMessage('Generation failed: ' . $e->getMessage(), 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
    }

    /**
     * Generate full chain (task + configuration + model) and show preview.
     */
    public function wizardGenerateChainAction(string $description = '', int $configurationUid = 0): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $description = trim($description);
        if ($description === '') {
            $this->enqueueFlashMessage('Please describe what this task should do.', 'Missing description', ContextualFeedbackSeverity::WARNING);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
        if (mb_strlen($description) > 2000) {
            $description = mb_substr($description, 0, 2000);
        }

        try {
            $config = $this->wizardGeneratorService->resolveConfiguration(
                $configurationUid > 0 ? $configurationUid : null,
            );
            $result = $this->wizardGeneratorService->generateTaskWithChain($description, $config);

            // Find best existing model and configuration
            $rawModelId = $result['recommended_model_id'] ?? '';
            $recommendedModelId = is_string($rawModelId) || is_numeric($rawModelId) ? (string)$rawModelId : '';
            $existingModel = $this->wizardGeneratorService->findBestExistingModel($recommendedModelId);
            $existingConfig = $this->wizardGeneratorService->findBestExistingConfiguration($description);

            $moduleTemplate->assignMultiple([
                'wizardType' => 'task',
                'description' => $description,
                'chain' => $result,
                'existingModel' => $existingModel,
                'existingConfig' => $existingConfig,
                'usedConfig' => $config,
                'configurationUid' => $configurationUid,
            ]);
            return $moduleTemplate->renderResponse('Backend/Task/WizardChainPreview');
        } catch (Throwable $e) {
            $this->enqueueFlashMessage('Generation failed: ' . $e->getMessage(), 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
    }

    /**
     * Create task with full chain (task + configuration + optional model) directly.
     *
     * Follows the SetupWizardController persistence pattern:
     * create entities with setters → repository->add() → persistAll() between tiers.
     */
    public function wizardCreateAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        if (!is_array($body)) {
            $this->enqueueFlashMessage('Invalid request.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }

        $taskData = is_array($body['task'] ?? null) ? $body['task'] : [];
        $configData = is_array($body['configuration'] ?? null) ? $body['configuration'] : [];
        $modelChoice = self::toStr($body['model_choice'] ?? 'existing');
        $existingModelUid = self::toInt($body['existing_model_uid'] ?? 0);
        $configChoice = self::toStr($body['config_choice'] ?? 'new');
        $existingConfigUid = self::toInt($body['existing_config_uid'] ?? 0);

        try {
            // Step 1: Resolve or create Model
            $model = null;
            if ($modelChoice === 'existing' && $existingModelUid > 0) {
                $model = $this->modelRepository->findByUid($existingModelUid);
            }
            if (!$model instanceof Model) {
                // Use default model as fallback
                $model = $this->modelRepository->findDefault();
            }
            if (!$model instanceof Model) {
                // Last resort: first active model
                foreach ($this->modelRepository->findActive() as $m) {
                    $model = $m;
                    break;
                }
            }

            // Step 2: Resolve or create Configuration
            $configuration = null;
            if ($configChoice === 'existing' && $existingConfigUid > 0) {
                $configuration = $this->configurationRepository->findByUid($existingConfigUid);
            }

            if ($configChoice === 'new' || !$configuration instanceof LlmConfiguration) {
                $configuration = new LlmConfiguration();
                $configuration->setIdentifier(self::toStr($configData['identifier'] ?? 'task-config'));
                $configuration->setName(self::toStr($configData['name'] ?? 'Task Configuration'));
                $configuration->setDescription(self::toStr($configData['description'] ?? ''));
                $configuration->setSystemPrompt(self::toStr($configData['system_prompt'] ?? ''));
                $configuration->setTemperature(max(0.0, min(2.0, self::toFloat($configData['temperature'] ?? 0.7))));
                $configuration->setMaxTokens(max(1, min(128000, self::toInt($configData['max_tokens'] ?? 4096))));
                $configuration->setTopP(max(0.0, min(1.0, self::toFloat($configData['top_p'] ?? 1.0))));
                $configuration->setFrequencyPenalty(max(-2.0, min(2.0, self::toFloat($configData['frequency_penalty'] ?? 0.0))));
                $configuration->setPresencePenalty(max(-2.0, min(2.0, self::toFloat($configData['presence_penalty'] ?? 0.0))));
                $configuration->setIsActive(true);
                if ($model instanceof Model) {
                    $configuration->setLlmModel($model);
                }
                $this->configurationRepository->add($configuration);
                $this->persistenceManager->persistAll();
            }

            // Step 3: Create Task
            $task = new Task();
            $task->setIdentifier(self::toStr($taskData['identifier'] ?? 'new-task'));
            $task->setName(self::toStr($taskData['name'] ?? 'New Task'));
            $task->setDescription(self::toStr($taskData['description'] ?? ''));
            $allowedCategories = ['content', 'log_analysis', 'system', 'developer', 'general'];
            $allowedOutputFormats = ['markdown', 'json', 'plain', 'html'];

            $category = self::toStr($taskData['category'] ?? 'general');
            $task->setCategory(in_array($category, $allowedCategories, true) ? $category : 'general');
            $task->setPromptTemplate(self::toStr($taskData['prompt_template'] ?? ''));

            $outputFormat = self::toStr($taskData['output_format'] ?? 'markdown');
            $task->setOutputFormat(in_array($outputFormat, $allowedOutputFormats, true) ? $outputFormat : 'markdown');
            $task->setConfiguration($configuration);
            $task->setIsActive(true);
            $this->taskRepository->add($task);
            $this->persistenceManager->persistAll();

            $this->enqueueFlashMessage(
                sprintf('Task "%s" created successfully with dedicated configuration.', $task->getName()),
                'Task Created',
                ContextualFeedbackSeverity::OK,
            );

            // Redirect to the task's execute form
            $taskUid = $task->getUid();
            if ($taskUid !== null) {
                return new RedirectResponse(
                    $this->uriBuilder->reset()->uriFor('executeForm', ['uid' => $taskUid]),
                );
            }

            return new RedirectResponse($this->uriBuilder->reset()->uriFor('list'));
        } catch (Throwable $e) {
            $this->enqueueFlashMessage('Failed to create task: ' . $e->getMessage(), 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
    }

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
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('list'));
        }

        // Register AJAX URLs for JavaScript
        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_task_list_tables' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_list_tables'),
            'nrllm_task_fetch_records' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_fetch_records'),
            'nrllm_task_load_record_data' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_load_record_data'),
            'nrllm_task_refresh_input' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_refresh_input'),
            'nrllm_task_execute' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_execute'),
        ]);

        // Load JavaScript module for task execution
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/TaskExecute.js');

        // Get input data based on input type
        $inputData = $this->getInputData($task);

        // Resolve the effective configuration (task's own or system default)
        $configuration = $task->getConfiguration();
        $isUsingDefault = false;
        if ($configuration === null) {
            $configuration = $this->configurationRepository->findDefault();
            $isUsingDefault = true;
        }

        // Return URL for FormEngine: back to this specific task's execute form
        $executeReturnUrl = (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_tasks', [
            'tx_nrllm_task[action]' => 'executeForm',
            'tx_nrllm_task[uid]' => $task->getUid(),
        ]);

        // Build edit URLs for the full chain: task → configuration → model → provider
        $taskEditUrl = $task->getUid() !== null
            ? (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [self::TABLE_NAME => [$task->getUid() => 'edit']],
                'returnUrl' => $executeReturnUrl,
            ])
            : null;

        $configEditUrl = null;
        $modelEditUrl = null;
        $providerEditUrl = null;

        if ($configuration !== null && $configuration->getUid() !== null) {
            $configEditUrl = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => ['tx_nrllm_configuration' => [$configuration->getUid() => 'edit']],
                'returnUrl' => $executeReturnUrl,
            ]);

            $model = $configuration->getLlmModel();
            if ($model !== null && $model->getUid() !== null) {
                $modelEditUrl = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => ['tx_nrllm_model' => [$model->getUid() => 'edit']],
                    'returnUrl' => $executeReturnUrl,
                ]);

                $provider = $model->getProvider();
                if ($provider !== null && $provider->getUid() !== null) {
                    $providerEditUrl = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                        'edit' => ['tx_nrllm_provider' => [$provider->getUid() => 'edit']],
                        'returnUrl' => $executeReturnUrl,
                    ]);
                }
            }
        }

        $moduleTemplate->assignMultiple([
            'task' => $task,
            'inputData' => $inputData,
            'requiresManualInput' => $task->requiresManualInput(),
            'effectiveConfig' => $configuration,
            'isUsingDefaultConfig' => $isUsingDefault,
            'configEditUrl' => $configEditUrl,
            'modelEditUrl' => $modelEditUrl,
            'providerEditUrl' => $providerEditUrl,
            'taskEditUrl' => $taskEditUrl,
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
            return new JsonResponse(['success' => false, 'error' => 'Task not found'], 404);
        }

        if (!$task->isActive()) {
            return new JsonResponse(['success' => false, 'error' => 'Task is not active'], 400);
        }

        try {
            // Build the prompt with input
            $prompt = $task->buildPrompt(['input' => $dto->input]);

            // Get configuration (lazy-loaded by Extbase)
            $configuration = $task->getConfiguration();

            // Execute the prompt
            if ($configuration !== null) {
                $response = $this->llmServiceManager->completeWithConfiguration($prompt, $configuration);
            } else {
                $response = $this->llmServiceManager->complete($prompt, new ChatOptions());
            }

            return new JsonResponse([
                'success' => true,
                'content' => $response->content,
                'model' => $response->model,
                'outputFormat' => $task->getOutputFormat(),
                'usage' => [
                    'promptTokens' => $response->usage->promptTokens,
                    'completionTokens' => $response->usage->completionTokens,
                    'totalTokens' => $response->usage->totalTokens,
                ],
            ]);
        } catch (Throwable $e) {
            // Return 200 with success:false so JavaScript can read the error message
            // HTTP 500 causes TYPO3's AjaxRequest to throw before parsing the JSON
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * List available database tables for table picker.
     */
    public function listTablesAction(): ResponseInterface
    {
        try {
            return new JsonResponse([
                'success' => true,
                'tables'  => $this->recordTableReader->listAllowedTables(),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch records from a database table.
     */
    public function fetchRecordsAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = FetchRecordsRequest::fromRequest($request);

        if (!$dto->isValid()) {
            return new JsonResponse(['success' => false, 'error' => 'No table specified'], 400);
        }

        try {
            // Tables without a uid column (e.g. tx_scheduler_task) cannot
            // back the picker. Short-circuit with an empty payload —
            // matches the previous behaviour.
            if (!$this->recordTableReader->tableHasUidColumn($dto->table)) {
                return new JsonResponse([
                    'success'    => true,
                    'records'    => [],
                    'labelField' => '',
                    'total'      => 0,
                ]);
            }

            $labelField = $dto->labelField !== ''
                ? $dto->labelField
                : $this->recordTableReader->detectLabelField($dto->table);

            $records = $this->recordTableReader->fetchSampleRecords(
                $dto->table,
                $labelField,
                $dto->limit,
            );

            return new JsonResponse([
                'success'    => true,
                'records'    => $records,
                'labelField' => $labelField,
                'total'      => count($records),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Load full record data for selected records.
     */
    public function loadRecordDataAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = LoadRecordDataRequest::fromRequest($request);

        if (!$dto->isValid()) {
            $error = $dto->table === '' || $dto->uids === ''
                ? 'Table and UIDs required'
                : 'No valid UIDs provided';
            return new JsonResponse(['success' => false, 'error' => $error], 400);
        }

        try {
            $rows = $this->recordTableReader->loadRecordsByUids($dto->table, $dto->uidList);

            return new JsonResponse([
                'success'     => true,
                'data'        => json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'recordCount' => count($rows),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh input data for a task via AJAX.
     */
    public function refreshInputAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = RefreshInputRequest::fromRequest($request);

        $task = $this->taskRepository->findByUid($dto->uid);
        if ($task === null) {
            return new JsonResponse(['success' => false, 'error' => 'Task not found'], 404);
        }

        $inputData = $this->getInputData($task);

        return new JsonResponse([
            'success' => true,
            'inputData' => $inputData,
            'inputType' => $task->getInputType(),
            'isEmpty' => $inputData === '' || $inputData === 'No deprecation log file found.',
        ]);
    }

    /**
     * Build FormEngine edit URL for a task.
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
        return (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_tasks');
    }

    /**
     * Format table name for display.
     */
    /**
     * Get input data for a task based on its input type.
     */
    private function getInputData(Task $task): string
    {
        return match ($task->getInputType()) {
            Task::INPUT_SYSLOG => $this->getSyslogData($task),
            Task::INPUT_DEPRECATION_LOG => $this->deprecationLogReader->readTail(),
            Task::INPUT_TABLE => $this->getTableData($task),
            default => '',
        };
    }

    /**
     * Get system log data.
     */
    private function getSyslogData(Task $task): string
    {
        $config = $task->getInputSourceArray();
        $limit = isset($config['limit']) && is_numeric($config['limit']) ? (int)$config['limit'] : 50;
        $errorOnly = isset($config['error_only']) ? (bool)$config['error_only'] : true;

        $rows = $this->systemLogReader->readRecent($limit, $errorOnly);

        $output = [];
        foreach ($rows as $row) {
            $tstamp = isset($row['tstamp']) && is_numeric($row['tstamp']) ? (int)$row['tstamp'] : 0;
            $typeValue = isset($row['type']) && is_numeric($row['type']) ? (int)$row['type'] : 0;
            $errorValue = isset($row['error']) && is_numeric($row['error']) ? (int)$row['error'] : 0;
            $details = isset($row['details']) && is_scalar($row['details']) ? (string)$row['details'] : '';

            $time = date('Y-m-d H:i:s', $tstamp);
            $type = match ($typeValue) {
                1 => LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.type.db', 'NrLlm') ?? 'DB',
                2 => LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.type.file', 'NrLlm') ?? 'FILE',
                3 => LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.type.cache', 'NrLlm') ?? 'CACHE',
                4 => LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.type.extension', 'NrLlm') ?? 'EXTENSION',
                5 => LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.type.error', 'NrLlm') ?? 'ERROR',
                254 => LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.type.setting', 'NrLlm') ?? 'SETTING',
                255 => LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.type.login', 'NrLlm') ?? 'LOGIN',
                default => LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.type.other', 'NrLlm') ?? 'OTHER',
            };
            $error = $errorValue > 0 ? (LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.errorMarker', 'NrLlm') ?? '[ERROR]') : '';
            $output[] = "[{$time}] [{$type}] {$error} {$details}";
        }

        return implode("\n", $output);
    }

    /**
     * Get data from a database table.
     */
    private function getTableData(Task $task): string
    {
        $config = $task->getInputSourceArray();
        $table = isset($config['table']) && is_scalar($config['table']) ? (string)$config['table'] : '';
        $limit = isset($config['limit']) && is_numeric($config['limit']) ? (int)$config['limit'] : 50;

        if ($table === '') {
            return LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.table.notConfigured', 'NrLlm') ?? 'No table configured.';
        }

        try {
            $rows = $this->recordTableReader->fetchAll($table, $limit);
        } catch (Throwable $e) {
            return sprintf(
                LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.table.readError', 'NrLlm') ?? 'Error reading table: %s',
                $e->getMessage(),
            );
        }

        return json_encode($rows, JSON_PRETTY_PRINT) ?: '[]';
    }

    /**
     * Enqueue a flash message notification.
     */
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
