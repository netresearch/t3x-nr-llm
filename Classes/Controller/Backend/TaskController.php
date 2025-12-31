<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Countable;
use Netresearch\NrLlm\Controller\Backend\DTO\ExecuteTaskRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\FetchRecordsRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\LoadRecordDataRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\RefreshInputRequest;
use Netresearch\NrLlm\Controller\Backend\DTO\TaskFormInput;
use Netresearch\NrLlm\Controller\Backend\DTO\TaskFormInputFactory;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Backend controller for managing one-shot prompt tasks.
 *
 * Tasks are simple, predefined prompts for common operations.
 * They are NOT AI agents - they cannot perform multi-step reasoning,
 * use tools, or maintain conversation context.
 */
#[AsController]
final class TaskController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly TaskRepository $taskRepository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly LlmServiceManager $llmServiceManager,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly FlashMessageService $flashMessageService,
        private readonly ConnectionPool $connectionPool,
        private readonly TaskFormInputFactory $taskFormInputFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    /**
     * List all tasks grouped by category.
     */
    public function listAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        /** @var \TYPO3\CMS\Extbase\Persistence\QueryResultInterface<int, Task>&Countable $tasks */
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

        foreach ($tasks as $task) {
            // @phpstan-ignore instanceof.alwaysTrue (defensive type guard for iterator)
            if (!$task instanceof Task) {
                continue;
            }
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
        ]);

        return $moduleTemplate->renderResponse('Backend/Task/List');
    }

    /**
     * Show form for creating a new task.
     */
    public function newAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $moduleTemplate->assignMultiple([
            'task' => null,
            'configurations' => $this->configurationRepository->findAll(),
            'categories' => Task::getCategories(),
            'inputTypes' => Task::getInputTypes(),
            'outputFormats' => Task::getOutputFormats(),
            'isNew' => true,
        ]);

        return $moduleTemplate->renderResponse('Backend/Task/Edit');
    }

    /**
     * Show form for editing an existing task.
     */
    public function editAction(int $uid): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $task = $this->taskRepository->findByUid($uid);
        if ($task === null) {
            $this->enqueueFlashMessage('Task not found.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse(
                $this->uriBuilder->reset()->uriFor('list'),
            );
        }

        $moduleTemplate->assignMultiple([
            'task' => $task,
            'configurations' => $this->configurationRepository->findAll(),
            'categories' => Task::getCategories(),
            'inputTypes' => Task::getInputTypes(),
            'outputFormats' => Task::getOutputFormats(),
            'isNew' => false,
        ]);

        return $moduleTemplate->renderResponse('Backend/Task/Edit');
    }

    /**
     * Save task (create or update).
     */
    public function saveAction(): ResponseInterface
    {
        $input = TaskFormInput::fromRequest($this->request);

        // Validate required fields
        if ($input->identifier === '' || $input->name === '') {
            $this->enqueueFlashMessage(
                'Identifier and name are required.',
                'Validation Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $input->isUpdate()
                    ? $this->uriBuilder->reset()->uriFor('edit', ['uid' => $input->uid])
                    : $this->uriBuilder->reset()->uriFor('new'),
            );
        }

        // Check identifier uniqueness
        if (!$this->taskRepository->isIdentifierUnique($input->identifier, $input->isUpdate() ? $input->uid : null)) {
            $this->enqueueFlashMessage(
                'A task with this identifier already exists.',
                'Validation Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse(
                $input->isUpdate()
                    ? $this->uriBuilder->reset()->uriFor('edit', ['uid' => $input->uid])
                    : $this->uriBuilder->reset()->uriFor('new'),
            );
        }

        if ($input->isUpdate()) {
            $task = $this->taskRepository->findByUid($input->uid);
            if ($task === null) {
                $this->enqueueFlashMessage('Task not found.', 'Error', ContextualFeedbackSeverity::ERROR);
                return new RedirectResponse($this->uriBuilder->reset()->uriFor('list'));
            }
            $this->taskFormInputFactory->updateFromInput($task, $input);
            $this->taskRepository->update($task);
            $message = 'Task updated successfully.';
        } else {
            $task = $this->taskFormInputFactory->createFromInput($input);
            $this->taskRepository->add($task);
            $message = 'Task created successfully.';
        }

        $this->persistenceManager->persistAll();
        $this->enqueueFlashMessage($message, 'Success', ContextualFeedbackSeverity::OK);

        return new RedirectResponse($this->uriBuilder->reset()->uriFor('list'));
    }

    /**
     * Delete a task.
     */
    public function deleteAction(int $uid): ResponseInterface
    {
        $task = $this->taskRepository->findByUid($uid);

        if ($task === null) {
            $this->enqueueFlashMessage('Task not found.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('list'));
        }

        if ($task->isSystem()) {
            $this->enqueueFlashMessage(
                'System tasks cannot be deleted.',
                'Error',
                ContextualFeedbackSeverity::ERROR,
            );
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('list'));
        }

        $this->taskRepository->remove($task);
        $this->persistenceManager->persistAll();

        $this->enqueueFlashMessage(
            sprintf('Task "%s" has been deleted.', $task->getName()),
            'Deleted',
            ContextualFeedbackSeverity::OK,
        );

        return new RedirectResponse($this->uriBuilder->reset()->uriFor('list'));
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
    public function executeAction(): ResponseInterface
    {
        $request = ExecuteTaskRequest::fromRequest($this->request);

        $task = $this->taskRepository->findByUid($request->uid);
        if ($task === null) {
            return new JsonResponse(['error' => 'Task not found'], 404);
        }

        if (!$task->isActive()) {
            return new JsonResponse(['error' => 'Task is not active'], 400);
        }

        try {
            // Build the prompt with input
            $prompt = $task->buildPrompt(['input' => $request->input]);

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
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
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
    public function fetchRecordsAction(): ResponseInterface
    {
        $request = FetchRecordsRequest::fromRequest($this->request);

        if (!$request->isValid()) {
            return new JsonResponse(['success' => false, 'error' => 'No table specified'], 400);
        }

        try {
            // Determine label field if not specified
            $labelField = $request->labelField !== ''
                ? $request->labelField
                : $this->detectLabelField($request->table);

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($request->table);

            // Build select fields
            $selectFields = ['uid'];
            if ($labelField !== '' && $labelField !== 'uid') {
                $selectFields[] = $labelField;
            }

            $queryBuilder
                ->select(...$selectFields)
                ->from($request->table)
                ->setMaxResults($request->limit);

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
    public function loadRecordDataAction(): ResponseInterface
    {
        $request = LoadRecordDataRequest::fromRequest($this->request);

        if (!$request->isValid()) {
            $error = $request->table === '' || $request->uids === ''
                ? 'Table and UIDs required'
                : 'No valid UIDs provided';
            return new JsonResponse(['success' => false, 'error' => $error], 400);
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($request->table);
            $queryBuilder
                ->select('*')
                ->from($request->table)
                ->where(
                    $queryBuilder->expr()->in('uid', $request->uidList),
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
    public function refreshInputAction(): ResponseInterface
    {
        $request = RefreshInputRequest::fromRequest($this->request);

        $task = $this->taskRepository->findByUid($request->uid);
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
        // Check TCA for ctrl.label
        /** @var array<string, array{ctrl?: array{label?: string}}>|null $tca */
        $tca = $GLOBALS['TCA'] ?? null;
        $labelField = $tca[$table]['ctrl']['label'] ?? null;
        if (is_string($labelField)) {
            return $labelField;
        }

        // Common label field names
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
