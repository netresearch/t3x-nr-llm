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
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

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
    private const string TABLE_NAME = 'tx_nrllm_task';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly TaskRepository $taskRepository,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly FlashMessageService $flashMessageService,
        private readonly ConnectionPool $connectionPool,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
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
            // @phpstan-ignore instanceof.alwaysTrue (defensive type guard for iterator)
            if (!$task instanceof Task) {
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
        ]);

        return $moduleTemplate->renderResponse('Backend/Task/List');
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
            $this->enqueueFlashMessage('Task not found.', 'Error', ContextualFeedbackSeverity::ERROR);
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

        $moduleTemplate->assignMultiple([
            'task' => $task,
            'inputData' => $inputData,
            'requiresManualInput' => $task->requiresManualInput(),
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
            $connection = $this->connectionPool->getConnectionByName('Default');
            $tables = $connection->createSchemaManager()->listTableNames();

            // Filter out internal/cache tables and format for display
            $relevantTables = [];
            foreach ($tables as $table) {
                // Skip cache, session, and some internal tables
                if (
                    str_starts_with($table, 'cache_')
                    || str_starts_with($table, 'cf_')
                    || str_starts_with($table, 'index_')
                    || in_array($table, ['sys_refindex', 'sys_registry', 'sys_history', 'sys_lockedrecords'], true)
                ) {
                    continue;
                }

                $relevantTables[] = [
                    'name' => $table,
                    'label' => $this->formatTableLabel($table),
                ];
            }

            // Sort by label
            usort($relevantTables, fn($a, $b) => strcasecmp($a['label'], $b['label']));

            return new JsonResponse([
                'success' => true,
                'tables' => $relevantTables,
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
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
            // Determine label field if not specified
            $labelField = $dto->labelField !== ''
                ? $dto->labelField
                : $this->detectLabelField($dto->table);

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($dto->table);

            // Build select fields
            $selectFields = ['uid'];
            if ($labelField !== '' && $labelField !== 'uid') {
                $selectFields[] = $labelField;
            }

            $queryBuilder
                ->select(...$selectFields)
                ->from($dto->table)
                ->setMaxResults($dto->limit);

            // Add ordering if we have a label field
            if ($labelField !== '' && $labelField !== 'uid') {
                $queryBuilder->orderBy($labelField, 'ASC');
            } else {
                $queryBuilder->orderBy('uid', 'DESC');
            }

            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

            // Format for display
            $records = array_map(static function (array $row) use ($labelField): array {
                $uid = isset($row['uid']) && is_numeric($row['uid']) ? (int)$row['uid'] : 0;
                $label = $labelField !== '' && isset($row[$labelField]) && is_scalar($row[$labelField])
                    ? (string)$row[$labelField]
                    : '';
                return [
                    'uid' => $uid,
                    'label' => $label !== '' ? $label : '[UID ' . $uid . ']',
                ];
            }, $rows);

            return new JsonResponse([
                'success' => true,
                'records' => $records,
                'labelField' => $labelField,
                'total' => count($records),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
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
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($dto->table);
            $queryBuilder
                ->select('*')
                ->from($dto->table)
                ->where(
                    $queryBuilder->expr()->in('uid', $dto->uidList),
                );

            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

            // Format as JSON for the input
            $formattedData = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return new JsonResponse([
                'success' => true,
                'data' => $formattedData,
                'recordCount' => count($rows),
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
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
    private function formatTableLabel(string $table): string
    {
        // Remove common prefixes
        $label = $table;
        if (str_starts_with($label, 'tx_')) {
            $label = substr($label, 3);
        } elseif (str_starts_with($label, 'sys_')) {
            $label = 'System: ' . substr($label, 4);
        } elseif (str_starts_with($label, 'be_')) {
            $label = 'Backend: ' . substr($label, 3);
        } elseif (str_starts_with($label, 'fe_')) {
            $label = 'Frontend: ' . substr($label, 3);
        }

        // Convert underscores to spaces and capitalize
        return ucwords(str_replace('_', ' ', $label));
    }

    /**
     * Detect the label field for a table.
     */
    private function detectLabelField(string $table): string
    {
        // Check TCA schema for label field
        if ($this->tcaSchemaFactory->has($table)) {
            $schema = $this->tcaSchemaFactory->get($table);
            if ($schema->hasCapability(TcaSchemaCapability::Label)) {
                $labelCapability = $schema->getCapability(TcaSchemaCapability::Label);
                $labelFieldName = $labelCapability->getPrimaryFieldName();
                if ($labelFieldName !== null) {
                    return $labelFieldName;
                }
            }
        }

        // Common label field names as fallback
        $commonFields = ['name', 'title', 'header', 'subject', 'username', 'email', 'identifier'];
        $connection = $this->connectionPool->getConnectionByName('Default');
        $columns = $connection->createSchemaManager()->listTableColumns($table);
        $columnNames = array_map(fn($col) => $col->getName(), $columns);

        foreach ($commonFields as $field) {
            if (in_array($field, $columnNames, true)) {
                return $field;
            }
        }

        return '';
    }

    /**
     * Get input data for a task based on its input type.
     */
    private function getInputData(Task $task): string
    {
        return match ($task->getInputType()) {
            Task::INPUT_SYSLOG => $this->getSyslogData($task),
            Task::INPUT_DEPRECATION_LOG => $this->getDeprecationLogData(),
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

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $queryBuilder
            ->select('*')
            ->from('sys_log')
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit);

        if ($errorOnly) {
            $queryBuilder->where(
                $queryBuilder->expr()->gt('error', 0),
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        $output = [];
        foreach ($rows as $row) {
            $tstamp = isset($row['tstamp']) && is_numeric($row['tstamp']) ? (int)$row['tstamp'] : 0;
            $typeValue = isset($row['type']) && is_numeric($row['type']) ? (int)$row['type'] : 0;
            $errorValue = isset($row['error']) && is_numeric($row['error']) ? (int)$row['error'] : 0;
            $details = isset($row['details']) && is_scalar($row['details']) ? (string)$row['details'] : '';

            $time = date('Y-m-d H:i:s', $tstamp);
            $type = match ($typeValue) {
                1 => 'DB',
                2 => 'FILE',
                3 => 'CACHE',
                4 => 'EXTENSION',
                5 => 'ERROR',
                254 => 'SETTING',
                255 => 'LOGIN',
                default => 'OTHER',
            };
            $error = $errorValue > 0 ? '[ERROR]' : '';
            $output[] = "[{$time}] [{$type}] {$error} {$details}";
        }

        return implode("\n", $output);
    }

    /**
     * Get deprecation log data.
     */
    private function getDeprecationLogData(): string
    {
        $logFile = GeneralUtility::getFileAbsFileName('var/log/typo3_deprecations.log');
        if (!file_exists($logFile)) {
            return 'No deprecation log file found.';
        }

        $content = file_get_contents($logFile);
        if ($content === false) {
            return 'Could not read deprecation log.';
        }

        // Get last 100 lines
        $lines = explode("\n", $content);
        $lines = array_slice($lines, -100);

        return implode("\n", $lines);
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
            return 'No table configured.';
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder
                ->select('*')
                ->from($table)
                ->setMaxResults($limit);

            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

            return json_encode($rows, JSON_PRETTY_PRINT) ?: '[]';
        } catch (Throwable $e) {
            return 'Error reading table: ' . $e->getMessage();
        }
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
