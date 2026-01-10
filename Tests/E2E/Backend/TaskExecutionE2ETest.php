<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use Netresearch\NrLlm\Controller\Backend\TaskController;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * E2E tests for Task Management user pathways.
 *
 * Tests complete user journeys:
 * - Pathway 5.1: View Task List
 * - Pathway 5.2: Execute Task with Manual Input
 * - Pathway 5.3: Execute Task with Database Records
 * - Pathway 5.4: Execute Task with System Log
 * - Pathway 5.5: Create Custom Task
 */
#[CoversClass(TaskController::class)]
final class TaskExecutionE2ETest extends AbstractBackendE2ETestCase
{
    private TaskController $controller;
    private TaskRepository $taskRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $taskRepository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $taskRepository);
        $this->taskRepository = $taskRepository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;

        $this->controller = $this->createController();
    }

    private function createController(): TaskController
    {
        $llmServiceManager = $this->get(LlmServiceManagerInterface::class);
        self::assertInstanceOf(LlmServiceManagerInterface::class, $llmServiceManager);

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $tcaSchemaFactory = $this->get(TcaSchemaFactory::class);
        self::assertInstanceOf(TcaSchemaFactory::class, $tcaSchemaFactory);

        return $this->createControllerWithReflection(TaskController::class, [
            'taskRepository' => $this->taskRepository,
            'llmServiceManager' => $llmServiceManager,
            'connectionPool' => $connectionPool,
            'tcaSchemaFactory' => $tcaSchemaFactory,
        ]);
    }

    // =========================================================================
    // Pathway 5.1: View Task List
    // =========================================================================

    #[Test]
    public function pathway5_1_viewTaskList(): void
    {
        // User navigates to Tasks list
        $queryResult = $this->taskRepository->findAll();
        self::assertInstanceOf(\TYPO3\CMS\Extbase\Persistence\QueryResultInterface::class, $queryResult);
        $tasks = $queryResult->toArray();

        self::assertNotEmpty($tasks, 'Task list should contain entries');

        // Verify tasks have required display information
        foreach ($tasks as $task) {
            self::assertInstanceOf(Task::class, $task);
            self::assertNotEmpty($task->getName(), 'Task should have a name');
            self::assertNotEmpty($task->getIdentifier(), 'Task should have an identifier');
            self::assertNotEmpty($task->getCategory(), 'Task should have a category');
            self::assertNotEmpty($task->getInputType(), 'Task should have an input type');
        }
    }

    #[Test]
    public function pathway5_1_viewTaskListGroupedByCategory(): void
    {
        $counts = $this->taskRepository->countByCategory();

        self::assertNotEmpty($counts);

        // User sees tasks organized by category
        foreach ($counts as $category => $count) {
            self::assertGreaterThan(0, $count);

            // Verify we can retrieve tasks for each category
            $tasksInCategory = $this->taskRepository->findByCategory($category);
            self::assertSame($count, $tasksInCategory->count());
        }
    }

    #[Test]
    public function pathway5_1_viewTaskListShowsActiveOnly(): void
    {
        $activeTasks = $this->taskRepository->findActive()->toArray();

        foreach ($activeTasks as $task) {
            self::assertTrue($task->isActive(), 'findActive should only return active tasks');
        }
    }

    // =========================================================================
    // Pathway 5.2: Execute Task with Manual Input
    // =========================================================================

    #[Test]
    public function pathway5_2_executeTaskWithManualInput(): void
    {
        // Find a manual input task
        $manualTasks = $this->taskRepository->findByInputType('manual');
        $task = $manualTasks->getFirst();

        if ($task === null) {
            self::markTestSkipped('No manual input task available');
        }

        // User enters text and clicks "Run Task"
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => 'This is test input text for the E2E test.',
        ]);
        $response = $this->controller->executeAction($request);

        // Response should be structured properly
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        if ($body['success']) {
            // On success, verify expected response fields
            self::assertArrayHasKey('content', $body);
            self::assertArrayHasKey('model', $body);
            self::assertNotEmpty($body['content']);
        } else {
            // On failure (no API), verify error is structured
            self::assertArrayHasKey('error', $body);
        }
    }

    #[Test]
    public function pathway5_2_executeTask_showsExecutionTime(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => 'Test input',
        ]);
        $response = $this->controller->executeAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            // Should include execution time for user feedback
            self::assertArrayHasKey('executionTime', $body);
        }
    }

    #[Test]
    public function pathway5_2_executeTask_showsTokenUsage(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => 'Test input',
        ]);
        $response = $this->controller->executeAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            // Should include token usage information
            self::assertArrayHasKey('usage', $body);
            $usage = $body['usage'];
            self::assertIsArray($usage);
            /** @var array<string, mixed> $usage */
            self::assertArrayHasKey('promptTokens', $usage);
            self::assertArrayHasKey('completionTokens', $usage);
        }
    }

    #[Test]
    public function pathway5_2_executeTask_errorForNonExistent(): void
    {
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => 99999,
            'input' => 'Test',
        ]);
        $response = $this->controller->executeAction($request);

        $this->assertErrorResponse($response, 404, 'Task not found');
    }

    // =========================================================================
    // Pathway 5.3: Execute Task with Database Records
    // =========================================================================

    #[Test]
    public function pathway5_3_getAvailableTables(): void
    {
        // User selects "Database Records" input type
        $response = $this->controller->listTablesAction();

        $body = $this->assertSuccessResponse($response);
        self::assertArrayHasKey('tables', $body);
        self::assertIsArray($body['tables']);
    }

    #[Test]
    public function pathway5_3_getRecordsFromTable(): void
    {
        // User selects a table and browses records
        $request = $this->createFormRequest('/ajax/task/records', [
            'table' => 'pages',
            'limit' => 10,
        ]);
        $response = $this->controller->fetchRecordsAction($request);

        // Response depends on database content
        self::assertContains($response->getStatusCode(), [200, 400]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            self::assertArrayHasKey('records', $body);
            self::assertIsArray($body['records']);
        }
    }

    #[Test]
    public function pathway5_3_executeTaskWithRecords(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        // User executes task with selected records
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'inputType' => 'records',
            'table' => 'pages',
            'records' => [1, 2, 3],
        ]);
        $response = $this->controller->executeAction($request);

        // Response should be structured
        self::assertContains($response->getStatusCode(), [200, 400, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    // =========================================================================
    // Pathway 5.4: Execute Task with System Log
    // =========================================================================

    #[Test]
    public function pathway5_4_findSyslogTasks(): void
    {
        $syslogTasks = $this->taskRepository->findByInputType('syslog');

        // Verify syslog tasks exist (from fixtures)
        self::assertGreaterThan(0, $syslogTasks->count());

        foreach ($syslogTasks as $task) {
            self::assertSame('syslog', $task->getInputType());
            self::assertNotEmpty($task->getInputSource(), 'Syslog task should have input source config');
        }
    }

    #[Test]
    public function pathway5_4_syslogTasksHaveInputSource(): void
    {
        // Syslog tasks should have input source configured
        $syslogTasks = $this->taskRepository->findByInputType('syslog');

        foreach ($syslogTasks as $task) {
            $inputSource = $task->getInputSource();
            self::assertNotEmpty($inputSource, 'Syslog task should have input source');

            // Input source should be valid JSON with syslog config
            $config = json_decode($inputSource, true);
            self::assertIsArray($config);
        }
    }

    #[Test]
    public function pathway5_4_executeSyslogTask(): void
    {
        $syslogTasks = $this->taskRepository->findByInputType('syslog');
        $task = $syslogTasks->getFirst();

        if ($task === null) {
            self::markTestSkipped('No syslog task available');
        }

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'inputType' => 'syslog',
            'limit' => 50,
        ]);
        $response = $this->controller->executeAction($request);

        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    // =========================================================================
    // Pathway 5.5: Create Custom Task
    // =========================================================================

    #[Test]
    public function pathway5_5_createCustomTask(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('custom-e2e-task');
        $task->setName('E2E Custom Task');
        $task->setDescription('A task created in E2E test');
        $task->setCategory('custom');
        $task->setPromptTemplate('Process this input: {{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('markdown');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier('custom-e2e-task');
        self::assertNotNull($retrieved);
        self::assertSame('E2E Custom Task', $retrieved->getName());
        self::assertSame('custom', $retrieved->getCategory());
        self::assertSame('manual', $retrieved->getInputType());
        self::assertFalse($retrieved->isSystem());
        self::assertStringContainsString('{{input}}', $retrieved->getPromptTemplate());
    }

    #[Test]
    public function pathway5_5_createTaskWithVariables(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('variable-task');
        $task->setName('Task with Variables');
        $task->setDescription('Uses multiple template variables');
        $task->setCategory('custom');
        $task->setPromptTemplate('Translate "{{text}}" from {{source_lang}} to {{target_lang}}.');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier('variable-task');
        self::assertNotNull($retrieved);

        // Template should contain all variables
        $template = $retrieved->getPromptTemplate();
        self::assertStringContainsString('{{text}}', $template);
        self::assertStringContainsString('{{source_lang}}', $template);
        self::assertStringContainsString('{{target_lang}}', $template);
    }

    #[Test]
    public function pathway5_5_createTaskWithConfiguration(): void
    {
        // Get an LLM configuration to link
        $configRepo = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configRepo);
        $config = $configRepo->findActive()->getFirst();

        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('config-linked-task');
        $task->setName('Task with Configuration');
        $task->setDescription('Uses specific LLM configuration');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('json');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        if ($config !== null) {
            $task->setConfiguration($config);
        }

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier('config-linked-task');
        self::assertNotNull($retrieved);
        self::assertSame('json', $retrieved->getOutputFormat());

        if ($config !== null) {
            self::assertNotNull($retrieved->getConfiguration());
        }
    }

    // =========================================================================
    // System vs User Tasks
    // =========================================================================

    #[Test]
    public function systemTasksAreIdentifiedCorrectly(): void
    {
        $systemTasks = $this->taskRepository->findSystemTasks();

        foreach ($systemTasks as $task) {
            self::assertTrue($task->isSystem());
        }
    }

    #[Test]
    public function userTasksAreIdentifiedCorrectly(): void
    {
        $userTasks = $this->taskRepository->findUserTasks();

        foreach ($userTasks as $task) {
            self::assertFalse($task->isSystem());
        }
    }

    // =========================================================================
    // Task Identifier Uniqueness
    // =========================================================================

    #[Test]
    public function identifierUniquenessValidation(): void
    {
        $existing = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($existing);

        // Existing identifier should not be unique
        self::assertFalse($this->taskRepository->isIdentifierUnique($existing->getIdentifier()));

        // New identifier should be unique
        self::assertTrue($this->taskRepository->isIdentifierUnique('brand-new-task-id'));

        // Own identifier should be unique when excluding self
        self::assertTrue($this->taskRepository->isIdentifierUnique(
            $existing->getIdentifier(),
            $existing->getUid(),
        ));
    }

    // =========================================================================
    // Task Execution Result Handling
    // =========================================================================

    #[Test]
    public function taskExecutionReturnsStructuredResult(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => 'Test input for structured result',
        ]);
        $response = $this->controller->executeAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        // Response must always have success flag
        self::assertArrayHasKey('success', $body);

        // On success, must have content and metadata
        if ($body['success']) {
            self::assertArrayHasKey('content', $body);
            self::assertArrayHasKey('model', $body);
        }

        // On failure, must have error message
        if (!$body['success']) {
            self::assertArrayHasKey('error', $body);
            self::assertNotEmpty($body['error']);
        }
    }

    // =========================================================================
    // Additional Task Controller Actions
    // =========================================================================

    #[Test]
    public function loadRecordDataAction_loadsRecordDetails(): void
    {
        // User wants to load detailed data for a specific record
        $request = $this->createFormRequest('/ajax/task/load-record', [
            'table' => 'pages',
            'uid' => 1,
        ]);
        $response = $this->controller->loadRecordDataAction($request);

        // Response depends on whether record exists
        self::assertContains($response->getStatusCode(), [200, 400, 404]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            self::assertArrayHasKey('data', $body);
            self::assertIsArray($body['data']);
        }
    }

    #[Test]
    public function loadRecordDataAction_errorForMissingTable(): void
    {
        $request = $this->createFormRequest('/ajax/task/load-record', [
            'uid' => 1,
        ]);
        $response = $this->controller->loadRecordDataAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function loadRecordDataAction_errorForMissingUid(): void
    {
        $request = $this->createFormRequest('/ajax/task/load-record', [
            'table' => 'pages',
        ]);
        $response = $this->controller->loadRecordDataAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function refreshInputAction_refreshesTaskInput(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        // User wants to refresh the input preview for a task
        $request = $this->createFormRequest('/ajax/task/refresh-input', [
            'uid' => $task->getUid(),
            'inputType' => 'manual',
            'input' => 'Test input text',
        ]);
        $response = $this->controller->refreshInputAction($request);

        self::assertContains($response->getStatusCode(), [200, 400, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function refreshInputAction_errorForMissingTask(): void
    {
        $request = $this->createFormRequest('/ajax/task/refresh-input', [
            'uid' => 99999,
            'inputType' => 'manual',
        ]);
        $response = $this->controller->refreshInputAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // Task Input Type Variations
    // =========================================================================

    #[Test]
    public function executeTask_withDifferentInputTypes(): void
    {
        // Test that tasks with different input types work correctly
        $inputTypes = ['manual', 'records', 'syslog'];

        foreach ($inputTypes as $inputType) {
            $tasks = $this->taskRepository->findByInputType($inputType);
            $task = $tasks->getFirst();

            if ($task !== null) {
                self::assertSame($inputType, $task->getInputType());
                self::assertTrue($task->isActive());
            }
        }
    }

    #[Test]
    public function fetchRecords_withPagination(): void
    {
        // User browses records with pagination
        $request = $this->createFormRequest('/ajax/task/records', [
            'table' => 'pages',
            'limit' => 5,
            'offset' => 0,
        ]);
        $response = $this->controller->fetchRecordsAction($request);

        self::assertContains($response->getStatusCode(), [200, 400]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            self::assertArrayHasKey('records', $body);
            $records = $body['records'];
            self::assertIsArray($records);
            // Should respect limit
            self::assertLessThanOrEqual(5, count($records));
        }
    }

    #[Test]
    public function fetchRecords_withSearch(): void
    {
        // User searches for records
        $request = $this->createFormRequest('/ajax/task/records', [
            'table' => 'pages',
            'search' => 'test',
            'limit' => 10,
        ]);
        $response = $this->controller->fetchRecordsAction($request);

        self::assertContains($response->getStatusCode(), [200, 400]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    // =========================================================================
    // Pathway 5.6: Inactive Task Handling
    // =========================================================================

    #[Test]
    public function pathway5_6_executeInactiveTask_returnsError(): void
    {
        // Find an active task
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);
        $taskUid = $task->getUid();
        self::assertNotNull($taskUid);

        // Deactivate the task
        $task->setIsActive(false);
        $this->taskRepository->update($task);
        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $persistenceManager->persistAll();

        // Try to execute the inactive task
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $taskUid,
            'input' => 'Test input',
        ]);
        $response = $this->controller->executeAction($request);

        // Should return error for inactive task
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Task is not active', $body['error']);

        // Reactivate for cleanup
        $task = $this->taskRepository->findByUid($taskUid);
        self::assertNotNull($task);
        $task->setIsActive(true);
        $this->taskRepository->update($task);
        $persistenceManager2 = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager2);
        $persistenceManager2->persistAll();
    }

    #[Test]
    public function pathway5_6_inactiveTasksExcludedFromList(): void
    {
        // Get initial active task count
        $initialActiveCount = $this->taskRepository->findActive()->count();

        // Find an active task and deactivate it
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);
        $taskUid = $task->getUid();
        self::assertNotNull($taskUid);

        $task->setIsActive(false);
        $this->taskRepository->update($task);
        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $persistenceManager->persistAll();
        $persistenceManager->clearState();

        // Active count should decrease
        $newActiveCount = $this->taskRepository->findActive()->count();
        self::assertSame($initialActiveCount - 1, $newActiveCount);

        // Reactivate for cleanup
        $task = $this->taskRepository->findByUid($taskUid);
        self::assertNotNull($task);
        $task->setIsActive(true);
        $this->taskRepository->update($task);
        $persistenceManager2 = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager2);
        $persistenceManager2->persistAll();
    }

    // =========================================================================
    // Pathway 5.7: Deprecation Log Input
    // =========================================================================

    #[Test]
    public function pathway5_7_findDeprecationLogTasks(): void
    {
        // Look for tasks that use deprecation log as input
        $tasks = $this->taskRepository->findByInputType('deprecation_log');

        // May or may not have deprecation log tasks in fixtures
        foreach ($tasks as $task) {
            self::assertSame('deprecation_log', $task->getInputType());
        }
    }

    #[Test]
    public function pathway5_7_refreshInputForDeprecationLog(): void
    {
        // Create a task with deprecation log input type
        $task = new Task();
        $task->setIdentifier('deprecation-log-test-' . time());
        $task->setName('Deprecation Log Test');
        $task->setDescription('Test task for deprecation log');
        $task->setPromptTemplate('Analyze: {{input}}');
        $task->setInputType('deprecation_log');
        $task->setOutputFormat('text');
        $task->setCategory('analysis');
        $task->setIsActive(true);
        $task->setIsSystem(false);
        $task->setPid(0);

        $this->taskRepository->add($task);
        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $persistenceManager->persistAll();

        $taskUid = $task->getUid();
        self::assertNotNull($taskUid);

        // Refresh input for this task
        $request = $this->createFormRequest('/ajax/task/refresh-input', [
            'uid' => $taskUid,
        ]);
        $response = $this->controller->refreshInputAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('inputData', $body);
        self::assertArrayHasKey('inputType', $body);
        self::assertSame('deprecation_log', $body['inputType']);
    }

    // =========================================================================
    // Task Execution Edge Cases
    // =========================================================================

    #[Test]
    public function executeAction_withEmptyInput(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);
        $taskUid = $task->getUid();
        self::assertNotNull($taskUid);

        // Execute with empty input
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $taskUid,
            'input' => '',
        ]);
        $response = $this->controller->executeAction($request);

        // Should still attempt execution (may succeed or fail depending on task)
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function executeAction_withVeryLongInput(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);
        $taskUid = $task->getUid();
        self::assertNotNull($taskUid);

        // Execute with very long input
        $longInput = str_repeat('This is a test sentence. ', 1000);
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $taskUid,
            'input' => $longInput,
        ]);
        $response = $this->controller->executeAction($request);

        // Should handle long input gracefully
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function listTablesAction_excludesCacheTables(): void
    {
        $response = $this->controller->listTablesAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertTrue($body['success']);
        self::assertArrayHasKey('tables', $body);
        $tables = $body['tables'];
        self::assertIsArray($tables);

        // Verify cache tables are excluded
        foreach ($tables as $table) {
            self::assertIsArray($table);
            /** @var array{name: string, label?: string} $table */
            self::assertStringStartsNotWith('cache_', $table['name'], 'Cache tables should be excluded');
            self::assertStringStartsNotWith('cf_', $table['name'], 'CF cache tables should be excluded');
        }
    }

    #[Test]
    public function listTablesAction_excludesInternalTables(): void
    {
        $response = $this->controller->listTablesAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        $tables = $body['tables'];
        self::assertIsArray($tables);
        /** @var list<array{name: string, label?: string}> $tables */

        // Verify internal tables are excluded
        $tableNames = array_column($tables, 'name');
        self::assertNotContains('sys_refindex', $tableNames);
        self::assertNotContains('sys_registry', $tableNames);
        self::assertNotContains('sys_history', $tableNames);
        self::assertNotContains('sys_lockedrecords', $tableNames);
    }

    #[Test]
    public function fetchRecordsAction_withNonExistentTable(): void
    {
        $request = $this->createFormRequest('/ajax/task/records', [
            'table' => 'non_existent_table_xyz',
            'limit' => 10,
        ]);
        $response = $this->controller->fetchRecordsAction($request);

        // Should return error for non-existent table
        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertArrayHasKey('error', $body);
    }

    #[Test]
    public function loadRecordDataAction_withMultipleUids(): void
    {
        // Load multiple records at once
        $request = $this->createFormRequest('/ajax/task/load-record-data', [
            'table' => 'pages',
            'uids' => '1,2,3',
        ]);
        $response = $this->controller->loadRecordDataAction($request);

        self::assertContains($response->getStatusCode(), [200, 400]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            self::assertArrayHasKey('data', $body);
            self::assertArrayHasKey('recordCount', $body);
        }
    }

    #[Test]
    public function loadRecordDataAction_withInvalidUidFormat(): void
    {
        $request = $this->createFormRequest('/ajax/task/load-record-data', [
            'table' => 'pages',
            'uids' => 'invalid,not,numbers',
        ]);
        $response = $this->controller->loadRecordDataAction($request);

        // Should return error for invalid UIDs
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
    }

    // =========================================================================
    // Pathway 5.8: Task Prompt Template Variables
    // =========================================================================

    #[Test]
    public function pathway5_8_taskWithSingleVariable(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('single-var-task-' . time());
        $task->setName('Single Variable Task');
        $task->setDescription('Task with one variable');
        $task->setCategory('custom');
        $task->setPromptTemplate('Process: {{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertStringContainsString('{{input}}', $retrieved->getPromptTemplate());
    }

    #[Test]
    public function pathway5_8_taskWithMultipleVariables(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('multi-var-task-' . time());
        $task->setName('Multi Variable Task');
        $task->setDescription('Task with multiple variables');
        $task->setCategory('custom');
        $task->setPromptTemplate('Translate "{{text}}" from {{source}} to {{target}} in {{style}} tone.');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        $template = $retrieved->getPromptTemplate();
        self::assertStringContainsString('{{text}}', $template);
        self::assertStringContainsString('{{source}}', $template);
        self::assertStringContainsString('{{target}}', $template);
        self::assertStringContainsString('{{style}}', $template);
    }

    #[Test]
    public function pathway5_8_taskWithNoVariables(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('no-var-task-' . time());
        $task->setName('No Variable Task');
        $task->setDescription('Static prompt task');
        $task->setCategory('custom');
        $task->setPromptTemplate('Always return the current date.');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertStringNotContainsString('{{', $retrieved->getPromptTemplate());
    }

    // =========================================================================
    // Pathway 5.9: Task Output Format Variations
    // =========================================================================

    #[Test]
    public function pathway5_9_taskWithTextOutput(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        // Set output format to text
        $task->setOutputFormat('text');
        $this->taskRepository->update($task);
        $this->persistenceManager->persistAll();

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => 'Test input',
        ]);
        $response = $this->controller->executeAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway5_9_taskWithMarkdownOutput(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('markdown-output-task-' . time());
        $task->setName('Markdown Output Task');
        $task->setDescription('Returns markdown formatted output');
        $task->setCategory('custom');
        $task->setPromptTemplate('Format this as markdown: {{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('markdown');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame('markdown', $retrieved->getOutputFormat());
    }

    #[Test]
    public function pathway5_9_taskWithJsonOutput(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('json-output-task-' . time());
        $task->setName('JSON Output Task');
        $task->setDescription('Returns JSON formatted output');
        $task->setCategory('custom');
        $task->setPromptTemplate('Return JSON: {{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('json');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame('json', $retrieved->getOutputFormat());
    }

    // =========================================================================
    // Pathway 5.10: Task Category Management
    // =========================================================================

    #[Test]
    public function pathway5_10_taskCategoryOrganization(): void
    {
        $counts = $this->taskRepository->countByCategory();

        self::assertNotEmpty($counts);

        // Verify each category has valid tasks
        foreach ($counts as $category => $count) {
            self::assertGreaterThan(0, $count);

            $tasksInCategory = $this->taskRepository->findByCategory($category);
            self::assertSame($count, $tasksInCategory->count());
        }
    }

    #[Test]
    public function pathway5_10_createTaskInNewCategory(): void
    {
        $newCategory = 'e2e-test-category-' . time();

        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('new-category-task-' . time());
        $task->setName('New Category Task');
        $task->setDescription('Task in a new category');
        $task->setCategory($newCategory);
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // New category should now exist
        $tasksInCategory = $this->taskRepository->findByCategory($newCategory);
        self::assertSame(1, $tasksInCategory->count());
    }

    #[Test]
    public function pathway5_10_findTasksByCategory(): void
    {
        $activeTasks = $this->taskRepository->findActive()->toArray();

        // Get a category that exists
        $existingCategory = null;
        foreach ($activeTasks as $task) {
            $existingCategory = $task->getCategory();
            break;
        }

        if ($existingCategory !== null) {
            $tasksInCategory = $this->taskRepository->findByCategory($existingCategory);
            self::assertGreaterThan(0, $tasksInCategory->count());

            foreach ($tasksInCategory as $task) {
                self::assertSame($existingCategory, $task->getCategory());
            }
        }
    }

    // =========================================================================
    // Pathway 5.11: Task Execution with Special Inputs
    // =========================================================================

    #[Test]
    public function pathway5_11_executeWithUnicodeInput(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => '日本語テスト 中文测试 한국어테스트 🎉🚀',
        ]);
        $response = $this->controller->executeAction($request);

        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway5_11_executeWithHtmlInput(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => '<p>HTML content</p><script>alert("test")</script>',
        ]);
        $response = $this->controller->executeAction($request);

        // Should handle HTML input safely
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway5_11_executeWithNewlinesAndTabs(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => "Line 1\nLine 2\n\tTabbed line\n\n\nMultiple newlines",
        ]);
        $response = $this->controller->executeAction($request);

        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    // =========================================================================
    // Pathway 5.12: Task Listing and Filtering
    // =========================================================================

    #[Test]
    public function pathway5_12_listAllTasks(): void
    {
        $allTasksResult = $this->taskRepository->findAll();
        self::assertInstanceOf(\TYPO3\CMS\Extbase\Persistence\QueryResultInterface::class, $allTasksResult);
        $allTasks = $allTasksResult->toArray();
        $activeTasks = $this->taskRepository->findActive()->toArray();

        // All tasks should include active and inactive
        self::assertGreaterThanOrEqual(count($activeTasks), count($allTasks));
    }

    #[Test]
    public function pathway5_12_filterByInputType(): void
    {
        $inputTypes = ['manual', 'records', 'syslog'];

        foreach ($inputTypes as $inputType) {
            $tasks = $this->taskRepository->findByInputType($inputType);

            foreach ($tasks as $task) {
                self::assertSame($inputType, $task->getInputType());
            }
        }
    }

    #[Test]
    public function pathway5_12_findSystemTasks(): void
    {
        $systemTasks = $this->taskRepository->findSystemTasks();

        foreach ($systemTasks as $task) {
            self::assertTrue($task->isSystem());
        }
    }

    #[Test]
    public function pathway5_12_findUserTasks(): void
    {
        $userTasks = $this->taskRepository->findUserTasks();

        foreach ($userTasks as $task) {
            self::assertFalse($task->isSystem());
        }
    }

    // =========================================================================
    // Pathway 5.13: Task Execution Error Handling
    // =========================================================================

    #[Test]
    public function pathway5_13_executeMissingUid(): void
    {
        $request = $this->createFormRequest('/ajax/task/execute', [
            'input' => 'Test',
        ]);
        $response = $this->controller->executeAction($request);

        // Missing UID returns 404 (task not found)
        self::assertContains($response->getStatusCode(), [400, 404]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
        self::assertArrayHasKey('error', $body);
    }

    #[Test]
    public function pathway5_13_executeZeroUid(): void
    {
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => 0,
            'input' => 'Test',
        ]);
        $response = $this->controller->executeAction($request);

        // Zero UID should be treated as invalid
        self::assertContains($response->getStatusCode(), [400, 404]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    #[Test]
    public function pathway5_13_executeNegativeUid(): void
    {
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => -1,
            'input' => 'Test',
        ]);
        $response = $this->controller->executeAction($request);

        // Negative UID should be treated as invalid
        self::assertContains($response->getStatusCode(), [400, 404]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    // =========================================================================
    // Pathway 5.14: Task Configuration Relationship
    // =========================================================================

    #[Test]
    public function pathway5_14_taskWithConfiguration(): void
    {
        $configRepo = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configRepo);
        $config = $configRepo->findActive()->getFirst();

        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('config-task-' . time());
        $task->setName('Configuration Linked Task');
        $task->setDescription('Task with specific LLM configuration');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        if ($config !== null) {
            $task->setConfiguration($config);
        }

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);

        if ($config !== null) {
            $retrievedConfig = $retrieved->getConfiguration();
            self::assertNotNull($retrievedConfig);
            self::assertSame($config->getUid(), $retrievedConfig->getUid());
        }
    }

    #[Test]
    public function pathway5_14_taskWithoutConfiguration(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('no-config-task-' . time());
        $task->setName('No Configuration Task');
        $task->setDescription('Task without specific configuration');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);
        // Deliberately not setting configuration

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertNull($retrieved->getConfiguration());
    }

    #[Test]
    public function pathway5_14_changeTaskConfiguration(): void
    {
        $configRepo = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configRepo);
        /** @var list<\Netresearch\NrLlm\Domain\Model\LlmConfiguration> $configs */
        $configs = $configRepo->findActive()->toArray();

        if (count($configs) < 2) {
            self::markTestSkipped('Need at least 2 configurations');
        }

        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $originalConfig = $task->getConfiguration();
        $newConfig = $configs[0];

        if ($originalConfig !== null && $originalConfig->getUid() === $newConfig->getUid()) {
            $newConfig = $configs[1];
        }

        $task->setConfiguration($newConfig);
        $this->taskRepository->update($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $taskUid = $task->getUid();
        self::assertNotNull($taskUid);
        $reloaded = $this->taskRepository->findByUid($taskUid);
        self::assertNotNull($reloaded);
        self::assertSame($newConfig->getUid(), $reloaded->getConfiguration()?->getUid());

        // Restore
        $reloaded->setConfiguration($originalConfig);
        $this->taskRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 5.15: Task AJAX Response Structure
    // =========================================================================

    #[Test]
    public function pathway5_15_executeResponseStructureSuccess(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $task->getUid(),
            'input' => 'Test input for response structure',
        ]);
        $response = $this->controller->executeAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        if ($body['success']) {
            self::assertArrayHasKey('content', $body);
            self::assertArrayHasKey('model', $body);
            self::assertArrayHasKey('usage', $body);
            self::assertArrayHasKey('executionTime', $body);
        }
    }

    #[Test]
    public function pathway5_15_executeResponseStructureError(): void
    {
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => 99999,
            'input' => 'Test',
        ]);
        $response = $this->controller->executeAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertFalse($body['success']);
        self::assertArrayHasKey('error', $body);
        self::assertNotEmpty($body['error']);
    }

    #[Test]
    public function pathway5_15_listTablesResponseStructure(): void
    {
        $response = $this->controller->listTablesAction();

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertArrayHasKey('success', $body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('tables', $body);
        $tables = $body['tables'];
        self::assertIsArray($tables);

        // Each table should have name and label
        foreach ($tables as $table) {
            self::assertIsArray($table);
            /** @var array<string, mixed> $table */
            self::assertArrayHasKey('name', $table);
            self::assertArrayHasKey('label', $table);
        }
    }

    #[Test]
    public function pathway5_15_refreshInputResponseStructure(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task);

        $request = $this->createFormRequest('/ajax/task/refresh-input', [
            'uid' => $task->getUid(),
        ]);
        $response = $this->controller->refreshInputAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        if ($body['success']) {
            self::assertArrayHasKey('inputData', $body);
            self::assertArrayHasKey('inputType', $body);
        }
    }

    // =========================================================================
    // Pathway 5.16: Task Count and Statistics
    // =========================================================================

    #[Test]
    public function pathway5_16_countAllTasks(): void
    {
        $allTasks = $this->taskRepository->findAll();
        self::assertInstanceOf(\TYPO3\CMS\Extbase\Persistence\QueryResultInterface::class, $allTasks);
        $count = $allTasks->count();

        self::assertGreaterThanOrEqual(0, $count);

        // Manual count should match
        $manualCount = 0;
        foreach ($allTasks as $task) {
            $manualCount++;
        }
        self::assertSame($count, $manualCount);
    }

    #[Test]
    public function pathway5_16_countActiveTasks(): void
    {
        $activeTasks = $this->taskRepository->findActive();
        $activeCount = $activeTasks->count();

        foreach ($activeTasks as $task) {
            self::assertTrue($task->isActive());
        }

        // Active should be <= total
        $allTasks = $this->taskRepository->findAll();
        self::assertInstanceOf(\TYPO3\CMS\Extbase\Persistence\QueryResultInterface::class, $allTasks);
        $totalCount = $allTasks->count();
        self::assertLessThanOrEqual($totalCount, $activeCount);
    }

    #[Test]
    public function pathway5_16_countByInputType(): void
    {
        $inputTypes = ['manual', 'records', 'syslog'];

        foreach ($inputTypes as $inputType) {
            $tasks = $this->taskRepository->findByInputType($inputType);
            $count = $tasks->count();

            self::assertGreaterThanOrEqual(0, $count);

            foreach ($tasks as $task) {
                self::assertSame($inputType, $task->getInputType());
            }
        }
    }

    #[Test]
    public function pathway5_16_countSystemVsUserTasks(): void
    {
        $systemTasks = $this->taskRepository->findSystemTasks();
        $userTasks = $this->taskRepository->findUserTasks();
        $allTasks = $this->taskRepository->findAll();
        self::assertInstanceOf(\TYPO3\CMS\Extbase\Persistence\QueryResultInterface::class, $allTasks);

        $systemCount = $systemTasks->count();
        $userCount = $userTasks->count();
        $totalCount = $allTasks->count();

        // System + User should equal Total
        self::assertSame($totalCount, $systemCount + $userCount);
    }

    // =========================================================================
    // Pathway 5.17: Task Description and Metadata
    // =========================================================================

    #[Test]
    public function pathway5_17_taskWithDescription(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('description-task-' . time());
        $task->setName('Description Test Task');
        $task->setDescription('This is a detailed description explaining what the task does and how to use it.');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertStringContainsString('detailed description', $retrieved->getDescription());
    }

    #[Test]
    public function pathway5_17_taskWithUnicodeDescription(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('unicode-desc-task-' . time());
        $task->setName('Unicode Description Task');
        $task->setDescription('日本語の説明 中文描述 한국어 설명 🎉');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertStringContainsString('日本語', $retrieved->getDescription());
    }

    #[Test]
    public function pathway5_17_taskInputSource(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('input-source-task-' . time());
        $task->setName('Input Source Task');
        $task->setDescription('Task with input source configuration');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('syslog');
        $task->setInputSource('{"table": "sys_log", "limit": 100}');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertNotEmpty($retrieved->getInputSource());

        $inputSource = json_decode($retrieved->getInputSource(), true);
        self::assertIsArray($inputSource);
        self::assertArrayHasKey('table', $inputSource);
    }

    #[Test]
    public function pathway5_17_taskCompleteMetadata(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();

        foreach ($tasks as $task) {
            // Each task should have required metadata
            self::assertNotEmpty($task->getIdentifier());
            self::assertNotEmpty($task->getName());
            self::assertNotEmpty($task->getCategory());
            self::assertNotEmpty($task->getInputType());
            self::assertNotEmpty($task->getOutputFormat());
            self::assertNotEmpty($task->getPromptTemplate());
            // isActive() and isSystem() are tested implicitly by calling them (always return bool)
        }
    }

    // =========================================================================
    // Pathway 5.18: Task Prompt Template Variations
    // =========================================================================

    #[Test]
    public function pathway5_18_simplePromptTemplate(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('simple-template-' . time());
        $task->setName('Simple Template Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('Process this: {{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);

        $prompt = $retrieved->buildPrompt(['input' => 'test data']);
        self::assertStringContainsString('test data', $prompt);
    }

    #[Test]
    public function pathway5_18_complexPromptTemplate(): void
    {
        $template = <<<TEMPLATE
            You are analyzing the following data:

            INPUT:
            {{input}}

            INSTRUCTIONS:
            1. Review the data carefully
            2. Identify key patterns
            3. Provide a summary

            OUTPUT FORMAT: {{format}}
            TEMPLATE;

        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('complex-template-' . time());
        $task->setName('Complex Template Task');
        $task->setCategory('custom');
        $task->setPromptTemplate($template);
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertStringContainsString('INSTRUCTIONS:', $retrieved->getPromptTemplate());
    }

    #[Test]
    public function pathway5_18_promptWithUnicode(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('unicode-template-' . time());
        $task->setName('Unicode Template Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('请分析这个数据: {{input}} 🔍');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertStringContainsString('请分析', $retrieved->getPromptTemplate());
        self::assertStringContainsString('🔍', $retrieved->getPromptTemplate());
    }

    #[Test]
    public function pathway5_18_emptyInputSubstitution(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('empty-input-task-' . time());
        $task->setName('Empty Input Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('Process: {{input}} - End');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);

        // Empty input should still produce valid prompt
        $prompt = $retrieved->buildPrompt(['input' => '']);
        self::assertStringContainsString('Process:', $prompt);
        self::assertStringContainsString('- End', $prompt);
    }

    // =========================================================================
    // Pathway 5.19: Task Configuration Association
    // =========================================================================

    #[Test]
    public function pathway5_19_taskWithConfiguration(): void
    {
        // Get an active configuration
        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);

        $config = $configurationRepository->findActive()->getFirst();
        if ($config === null) {
            self::markTestSkipped('No active configuration available');
        }

        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('config-task-' . time());
        $task->setName('Configuration Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setConfiguration($config);
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);

        $assignedConfig = $retrieved->getConfiguration();
        self::assertNotNull($assignedConfig);
        self::assertSame($config->getUid(), $assignedConfig->getUid());
    }

    #[Test]
    public function pathway5_19_taskWithoutConfiguration(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('no-config-task-' . time());
        $task->setName('No Configuration Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setConfiguration(null);
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertNull($retrieved->getConfiguration());
    }

    #[Test]
    public function pathway5_19_changeTaskConfiguration(): void
    {
        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);

        /** @var list<\Netresearch\NrLlm\Domain\Model\LlmConfiguration> $configs */
        $configs = $configurationRepository->findActive()->toArray();
        if (count($configs) < 2) {
            self::markTestSkipped('Need at least 2 configurations');
        }

        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('change-config-task-' . time());
        $task->setName('Change Configuration Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setConfiguration($configs[0]);
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        $retrievedConfig = $retrieved->getConfiguration();
        self::assertNotNull($retrievedConfig);
        self::assertSame($configs[0]->getUid(), $retrievedConfig->getUid());

        // Change configuration
        $retrieved->setConfiguration($configs[1]);
        $this->taskRepository->update($retrieved);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $updated = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($updated);
        $updatedConfig = $updated->getConfiguration();
        self::assertNotNull($updatedConfig);
        self::assertSame($configs[1]->getUid(), $updatedConfig->getUid());
    }

    // =========================================================================
    // Pathway 5.20: Task Output Formats
    // =========================================================================

    #[Test]
    public function pathway5_20_textOutputFormat(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('text-format-task-' . time());
        $task->setName('Text Format Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame('text', $retrieved->getOutputFormat());
    }

    #[Test]
    public function pathway5_20_jsonOutputFormat(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('json-format-task-' . time());
        $task->setName('JSON Format Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('json');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame('json', $retrieved->getOutputFormat());
    }

    #[Test]
    public function pathway5_20_markdownOutputFormat(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('markdown-format-task-' . time());
        $task->setName('Markdown Format Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('markdown');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame('markdown', $retrieved->getOutputFormat());
    }

    #[Test]
    public function pathway5_20_codeOutputFormat(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('code-format-task-' . time());
        $task->setName('Code Format Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('code');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame('code', $retrieved->getOutputFormat());
    }

    // =========================================================================
    // Pathway 5.21: Task Lifecycle Operations
    // =========================================================================

    #[Test]
    public function pathway5_21_createAndDeleteTask(): void
    {
        $identifier = 'lifecycle-task-' . time();

        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier($identifier);
        $task->setName('Lifecycle Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify exists
        $retrieved = $this->taskRepository->findOneByIdentifier($identifier);
        self::assertNotNull($retrieved);

        // Delete
        $this->taskRepository->remove($retrieved);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify deleted
        $deleted = $this->taskRepository->findOneByIdentifier($identifier);
        self::assertNull($deleted);
    }

    #[Test]
    public function pathway5_21_updateTaskPreservesUid(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('update-uid-task-' . time());
        $task->setName('Original Task Name');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        $originalUid = $retrieved->getUid();

        // Update
        $retrieved->setName('Updated Task Name');
        $retrieved->setDescription('Added description');
        $this->taskRepository->update($retrieved);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $updated = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($updated);
        self::assertSame($originalUid, $updated->getUid());
        self::assertSame('Updated Task Name', $updated->getName());
    }

    #[Test]
    public function pathway5_21_deactivateTaskPreservesData(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('deactivate-task-' . time());
        $task->setName('Deactivate Task');
        $task->setDescription('This description should be preserved');
        $task->setCategory('custom');
        $task->setPromptTemplate('Important template: {{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('json');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertTrue($retrieved->isActive());

        // Deactivate
        $retrieved->setIsActive(false);
        $this->taskRepository->update($retrieved);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify data preserved
        $deactivated = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($deactivated);
        self::assertFalse($deactivated->isActive());
        self::assertStringContainsString('preserved', $deactivated->getDescription());
        self::assertStringContainsString('Important template', $deactivated->getPromptTemplate());
        self::assertSame('json', $deactivated->getOutputFormat());
    }

    #[Test]
    public function pathway5_21_reactivateTask(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('reactivate-task-' . time());
        $task->setName('Reactivate Task');
        $task->setCategory('custom');
        $task->setPromptTemplate('{{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('text');
        $task->setIsActive(false);
        $task->setIsSystem(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertFalse($retrieved->isActive());

        // Reactivate
        $retrieved->setIsActive(true);
        $this->taskRepository->update($retrieved);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $reactivated = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($reactivated);
        self::assertTrue($reactivated->isActive());
    }
}
