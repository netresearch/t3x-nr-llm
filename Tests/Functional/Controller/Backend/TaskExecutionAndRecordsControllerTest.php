<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\TaskExecutionController;
use Netresearch\NrLlm\Controller\Backend\TaskRecordsController;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Task\DeprecationLogReaderInterface;
use Netresearch\NrLlm\Service\Task\RecordTableReaderInterface;
use Netresearch\NrLlm\Service\Task\SystemLogReaderInterface;
use Netresearch\NrLlm\Service\Task\TaskExecutionResult;
use Netresearch\NrLlm\Service\Task\TaskExecutionServiceInterface;
use Netresearch\NrLlm\Service\Task\TaskInputResolver;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for the Task AJAX actions, exercising the
 * post-slice-13e split: `TaskExecutionController` (execute /
 * refresh-input) and `TaskRecordsController` (list-tables /
 * fetch-records / load-record-data).
 *
 * Tests user pathways:
 * - Pathway 5.2: Execute Task with Manual Input
 * - Pathway 5.3: List Tables / Fetch Records
 * - Error cases: missing uid, non-existent task, inactive task
 *
 * The two controllers are instantiated via reflection so the
 * Extbase ActionController initialisation (which needs a real
 * request context) can be skipped — every test exercises a single
 * action method directly.
 */
#[CoversClass(TaskExecutionController::class)]
#[CoversClass(TaskRecordsController::class)]
final class TaskExecutionAndRecordsControllerTest extends AbstractFunctionalTestCase
{
    private const AJAX_NRLLM_TASK_LOAD_RECORD_DATA = '/ajax/nrllm/task/load-record-data';

    private const AJAX_NRLLM_TASK_FETCH_RECORDS = '/ajax/nrllm/task/fetch-records';

    private const AJAX_NRLLM_TASK_REFRESH_INPUT = '/ajax/nrllm/task/refresh-input';

    private const AJAX_NRLLM_TASK_EXECUTE = '/ajax/nrllm/task/execute';

    private const TASK_NOT_FOUND = 'Task not found';

    private const TEST_INPUT = 'test input';

    private TaskExecutionController $executionController;

    private TaskRecordsController $recordsController;

    private TaskRepository $taskRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');

        // The record-picker endpoints require an admin (ADR-037);
        // execution and input refresh require the TASKS_USE grant (ADR-130).
        // An admin passes both boundaries for these success-path tests.
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        // uid 1 is an admin (admin=1)
        $taskRepository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $taskRepository);
        $this->taskRepository = $taskRepository;

        $taskExecutionService = $this->get(TaskExecutionServiceInterface::class);
        self::assertInstanceOf(
            TaskExecutionServiceInterface::class,
            $taskExecutionService,
        );

        $recordTableReader = $this->get(RecordTableReaderInterface::class);
        self::assertInstanceOf(
            RecordTableReaderInterface::class,
            $recordTableReader,
        );

        $taskInputResolver = $this->get(TaskInputResolverInterface::class);
        self::assertInstanceOf(
            TaskInputResolverInterface::class,
            $taskInputResolver,
        );

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(
            PersistenceManagerInterface::class,
            $persistenceManager,
        );

        $this->executionController = $this->createExecutionController(
            $taskRepository,
            $taskExecutionService,
            $taskInputResolver,
        );
        $this->recordsController = $this->createRecordsController($recordTableReader);
    }

    private function createExecutionController(
        TaskRepository $taskRepository,
        TaskExecutionServiceInterface $taskExecutionService,
        TaskInputResolverInterface $taskInputResolver,
    ): TaskExecutionController {
        $reflection = new ReflectionClass(TaskExecutionController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($controller, 'taskRepository', $taskRepository);
        $this->setPrivateProperty($controller, 'taskExecutionService', $taskExecutionService);
        $this->setPrivateProperty($controller, 'taskInputResolver', $taskInputResolver);
        // executeAction logs provider/unexpected errors via LoggerInterface.
        $this->setPrivateProperty($controller, 'logger', new NullLogger());

        return $controller;
    }

    private function createRecordsController(
        RecordTableReaderInterface $recordTableReader,
    ): TaskRecordsController {
        $reflection = new ReflectionClass(TaskRecordsController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($controller, 'recordTableReader', $recordTableReader);
        // All record actions log failures via LoggerInterface.
        $this->setPrivateProperty($controller, 'logger', new NullLogger());

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    // ========================================
    // Pathway 5.2: Execute Task with Manual Input
    // ========================================

    #[Test]
    public function executeActionReturnsNotFoundForNonExistentTask(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_EXECUTE);
        $request = $request->withParsedBody(['uid' => 999, 'input' => self::TEST_INPUT]);

        // Act
        $response = $this->executionController->executeAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame(self::TASK_NOT_FOUND, $body['error']);
    }

    #[Test]
    public function executeActionReturnsErrorForInactiveTask(): void
    {
        // Task with uid=3 is inactive in fixture
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_EXECUTE);
        $request = $request->withParsedBody(['uid' => 3, 'input' => self::TEST_INPUT]);

        // Act
        $response = $this->executionController->executeAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Task is not active', $body['error']);
    }

    #[Test]
    public function executeActionHandlesZeroUidAsNotFound(): void
    {
        // UID 0 should be treated as "not found"
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_EXECUTE);
        $request = $request->withParsedBody(['uid' => 0, 'input' => self::TEST_INPUT]);

        // Act
        $response = $this->executionController->executeAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame(self::TASK_NOT_FOUND, $body['error']);
    }

    #[Test]
    public function executeActionHandlesStringUid(): void
    {
        // UID passed as string (common from form submissions)
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_EXECUTE);
        $request = $request->withParsedBody(['uid' => '999', 'input' => self::TEST_INPUT]);

        // Act
        $response = $this->executionController->executeAction($request);

        // Assert - should still process as 404 (non-existent task)
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
    }

    #[Test]
    public function executeActionAttemptsLlmCallForActiveTask(): void
    {
        $this->assertTaskExecutionResponse(1, 'Test input for analysis');
    }

    #[Test]
    public function listTablesActionReturnsTablesList(): void
    {
        // Act
        $response = $this->recordsController->listTablesAction();

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('tables', $body);
        $tables = $body['tables'];
        self::assertIsArray($tables);

        // Verify table structure
        self::assertNotSame([], $tables);
        $firstTable = $tables[0];
        self::assertIsArray($firstTable);
        self::assertArrayHasKey('name', $firstTable);
        self::assertArrayHasKey('label', $firstTable);
    }

    #[Test]
    public function listTablesActionExcludesCacheTables(): void
    {
        // Act
        $response = $this->recordsController->listTablesAction();

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);

        // Verify cache tables are excluded
        $tables = $body['tables'];
        self::assertIsArray($tables);
        $tableNames = array_column($tables, 'name');
        self::assertNotSame([], $tableNames);
        foreach ($tableNames as $tableName) {
            self::assertIsString($tableName);
            self::assertStringStartsNotWith('cache_', $tableName);
            self::assertStringStartsNotWith('cf_', $tableName);
        }
    }

    #[Test]
    public function listTablesActionIncludesExtensionTables(): void
    {
        // Act
        $response = $this->recordsController->listTablesAction();

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);

        // Verify our extension table is present
        $tables = $body['tables'];
        self::assertIsArray($tables);
        $tableNames = array_column($tables, 'name');
        self::assertContains('tx_nrllm_task', $tableNames);
    }

    // ========================================
    // Pathway 5.3: Fetch Records
    // ========================================

    #[Test]
    public function fetchRecordsActionReturnsRecordsForValidTable(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
        $request = $request->withParsedBody(['table' => 'tx_nrllm_task']);

        // Act
        $response = $this->recordsController->fetchRecordsAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('records', $body);
        self::assertIsArray($body['records']);
        self::assertArrayHasKey('total', $body);

        self::assertSame(3, $body['total']);
    }

    #[Test]
    public function fetchRecordsActionReturnsRecordStructureWithUidAndLabel(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
        $request = $request->withParsedBody(['table' => 'tx_nrllm_task']);

        // Act
        $response = $this->recordsController->fetchRecordsAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);

        // Verify record structure
        $records = $body['records'];
        self::assertIsArray($records);
        self::assertNotSame([], $records);
        $firstRecord = $records[0];
        self::assertIsArray($firstRecord);
        self::assertArrayHasKey('uid', $firstRecord);
        self::assertArrayHasKey('label', $firstRecord);
        self::assertIsInt($firstRecord['uid']);
        self::assertIsString($firstRecord['label']);
    }

    #[Test]
    public function fetchRecordsActionReturnsErrorForMissingTable(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->recordsController->fetchRecordsAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No table specified', $body['error']);
    }

    #[Test]
    public function fetchRecordsActionReturnsErrorForEmptyTableName(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
        $request = $request->withParsedBody(['table' => '']);

        // Act
        $response = $this->recordsController->fetchRecordsAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No table specified', $body['error']);
    }

    #[Test]
    public function fetchRecordsActionReturnsErrorForNonExistentTable(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
        $request = $request->withParsedBody(['table' => 'non_existent_table_xyz']);

        // Act
        $response = $this->recordsController->fetchRecordsAction($request);

        // A non-existent table has no uid column, so the reader short-circuits
        // to an empty 200 payload on SQLite (listTableColumns() returns []),
        // while MySQL/MariaDB raises a schema error mapped to 500. Both are
        // sane, non-crashing outcomes for an unknown table.
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        if ($response->getStatusCode() === 500) {
            self::assertFalse($body['success']);
            self::assertArrayHasKey('error', $body);
        } else {
            // SQLite: a non-existent table has no columns, the reader
            // short-circuits to a successful empty result.
            self::assertTrue($body['success']);
            self::assertSame([], $body['records']);
        }
    }

    #[Test]
    public function fetchRecordsActionRespectsLimitParameter(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
        $request = $request->withParsedBody(['table' => 'tx_nrllm_task', 'limit' => 2]);

        // Act
        $response = $this->recordsController->fetchRecordsAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        $records = $body['records'];
        self::assertIsArray($records);
        self::assertCount(2, $records);
    }

    #[Test]
    public function fetchRecordsActionUsesCustomLabelField(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
        $request = $request->withParsedBody(
            ['table' => 'tx_nrllm_task', 'labelField' => 'identifier'],
        );

        // Act
        $response = $this->recordsController->fetchRecordsAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame('identifier', $body['labelField']);
        self::assertIsArray($body['records']);
        $labels = array_column($body['records'], 'label', 'uid');
        ksort($labels);
        self::assertSame(
            [
                1 => 'test-manual-task',
                2 => 'test-syslog-task',
                3 => 'test-inactive-task',
            ],
            $labels,
        );
    }

    // ========================================
    // Repository Integration
    // ========================================

    #[Test]
    public function taskRepositoryFindsTaskByUid(): void
    {
        // Verify fixture is loaded correctly
        $task = $this->taskRepository->findByUid(1);
        self::assertNotNull($task);
        self::assertSame('test-manual-task', $task->getIdentifier());
        self::assertSame('Test Manual Task', $task->getName());
        self::assertTrue($task->isActive());
    }

    #[Test]
    public function taskRepositoryReturnsNullForDeletedTask(): void
    {
        self::assertNull($this->taskRepository->findByUid(4));
    }

    #[Test]
    public function taskRepositoryFindsInactiveTask(): void
    {
        // Task with uid=3 is inactive in fixture
        $task = $this->taskRepository->findByUid(3);
        self::assertNotNull($task);
        self::assertSame('test-inactive-task', $task->getIdentifier());
        self::assertFalse($task->isActive());
    }

    // ========================================
    // Pathway 5.3: Load Record Data
    // ========================================

    #[Test]
    public function loadRecordDataReturnsErrorForMissingTable(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_LOAD_RECORD_DATA);
        $request = $request->withParsedBody(['uids' => '1,2,3']);

        // Act
        $response = $this->recordsController->loadRecordDataAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Table and UIDs required', $body['error']);
    }

    #[Test]
    public function loadRecordDataReturnsErrorForMissingUids(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_LOAD_RECORD_DATA);
        $request = $request->withParsedBody(['table' => 'tx_nrllm_task']);

        // Act
        $response = $this->recordsController->loadRecordDataAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Table and UIDs required', $body['error']);
    }

    #[Test]
    public function loadRecordDataReturnsRecordsForValidRequest(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_LOAD_RECORD_DATA);
        $request = $request->withParsedBody(['table' => 'tx_nrllm_task', 'uids' => '1,2']);

        // Act
        $response = $this->recordsController->loadRecordDataAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('recordCount', $body);
        self::assertSame(2, $body['recordCount']);

        // Verify data is valid JSON
        $data = $body['data'];
        self::assertIsString($data);
        $parsedData = json_decode($data, true);
        self::assertIsArray($parsedData);
        $uids = array_column($parsedData, 'uid');
        sort($uids);
        self::assertSame([1, 2], $uids);
    }

    #[Test]
    public function loadRecordDataReturnsEmptyForNonExistentUids(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_LOAD_RECORD_DATA);
        $request = $request->withParsedBody([
            'table' => 'tx_nrllm_task',
            'uids' => '9999,9998',
        ]);

        // Act
        $response = $this->recordsController->loadRecordDataAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame(0, $body['recordCount']);
    }

    // ========================================
    // Pathway 5.3: Refresh Input Data
    // ========================================

    #[Test]
    public function refreshInputReturnsNotFoundForNonExistentTask(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_REFRESH_INPUT);
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->executionController->refreshInputAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame(self::TASK_NOT_FOUND, $body['error']);
    }

    #[Test]
    public function refreshInputReturnsDataForValidTask(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_REFRESH_INPUT);
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->executionController->refreshInputAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('inputData', $body);
        self::assertArrayHasKey('inputType', $body);
        self::assertArrayHasKey('isEmpty', $body);
        self::assertSame('manual', $body['inputType']);
        self::assertSame('', $body['inputData']);
        self::assertTrue($body['isEmpty']);
    }

    #[Test]
    public function refreshInputHandlesZeroUidAsNotFound(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_REFRESH_INPUT);
        $request = $request->withParsedBody(['uid' => 0]);

        // Act
        $response = $this->executionController->refreshInputAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame(self::TASK_NOT_FOUND, $body['error']);
    }

    // ========================================
    // Pathway 5.4: System Log Input Type
    // ========================================

    #[Test]
    public function refreshInputReturnsSyslogDataForSyslogTask(): void
    {
        // Import sys_log fixtures first
        $this->importFixture('SysLog.csv');

        // Task uid=2 is configured with input_type='syslog'
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_REFRESH_INPUT);
        $request = $request->withParsedBody(['uid' => 2]);

        // Act
        $response = $this->executionController->refreshInputAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame('syslog', $body['inputType']);
        self::assertArrayHasKey('inputData', $body);
        // Syslog data should contain formatted log entries
        self::assertIsString($body['inputData']);
    }

    #[Test]
    public function refreshInputReturnsSyslogEntriesWithErrorsFirst(): void
    {
        // Import sys_log fixtures
        $this->importFixture('SysLog.csv');

        // Task uid=2 has error_only=true in input_source
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_REFRESH_INPUT);
        $request = $request->withParsedBody(['uid' => 2]);

        // Act
        $response = $this->executionController->refreshInputAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);

        // When there are error entries, they should be included
        // (error_only=true filters to only error entries)
        self::assertFalse($body['isEmpty']);
        $inputData = $body['inputData'];
        self::assertIsString($inputData);
        self::assertStringContainsString('[ERROR]', $inputData);
        self::assertStringContainsString(
            'Extension error: Could not load configuration',
            $inputData,
        );
        self::assertStringContainsString('Too many login attempts', $inputData);
        self::assertStringContainsString(
            'Login failed for user: admin',
            $inputData,
        );
        self::assertStringNotContainsString(
            'Created content element',
            $inputData,
        );
        self::assertStringNotContainsString('Updated page', $inputData);
        self::assertStringNotContainsString('Deleted page', $inputData);
        $extension = strpos($inputData, 'Extension error:');
        $attempts = strpos($inputData, 'Too many login attempts');
        $login = strpos($inputData, 'Login failed for user:');
        self::assertIsInt($extension);
        self::assertIsInt($attempts);
        self::assertIsInt($login);
        self::assertLessThan($attempts, $extension);
        self::assertLessThan($login, $attempts);
    }

    #[Test]
    public function executeActionWithSyslogTaskProcessesLogData(): void
    {
        $this->assertTaskExecutionResponse(
            2,
            '[2024-12-23 10:00:00] [ERROR] Login failed for user: admin',
        );
    }

    #[Test]
    public function refreshInputHandlesDeprecationLogType(): void
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        self::assertSame(
            1,
            $pool
                ->getConnectionForTable('tx_nrllm_task')
                ->update(
                    'tx_nrllm_task',
                    ['input_type' => 'deprecation_log'],
                    ['uid' => 1],
                ),
        );
        $deprecations = $this->createMock(DeprecationLogReaderInterface::class);
        $deprecations
            ->expects(self::once())
            ->method('readTail')
            ->willReturn('Actual deprecation input');
        $systemLog = $this->createMock(SystemLogReaderInterface::class);
        $systemLog->expects(self::never())->method('readRecent');
        $tables = $this->createMock(RecordTableReaderInterface::class);
        $tables->expects(self::never())->method('fetchAll');
        $resolver = new TaskInputResolver(
            $systemLog,
            $deprecations,
            $tables,
            new NullLogger(),
        );
        $controller = $this->createExecutionController(
            $this->taskRepository,
            self::createStub(TaskExecutionServiceInterface::class),
            $resolver,
        );
        $request = (new ServerRequest('POST', self::AJAX_NRLLM_TASK_REFRESH_INPUT))->withParsedBody(
            ['uid' => 1],
        );

        $response = $controller->refreshInputAction($request);

        self::assertSame(200, $response->getStatusCode());
        $json = (string)$response->getBody();
        self::assertJson($json);
        self::assertSame(
            [
                'success' => true,
                'inputData' => 'Actual deprecation input',
                'inputType' => 'deprecation_log',
                'isEmpty' => false,
            ],
            json_decode($json, true),
        );
    }

    // ========================================
    // Pathway 5.3: Table Input Type
    // ========================================

    #[Test]
    public function fetchRecordsActionReturnsEmptyForTableWithoutUidColumn(): void
    {
        // Create a temporary table without uid column
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $conn = $connectionPool->getConnectionByName('Default');
        $conn->executeStatement('CREATE TABLE IF NOT EXISTS test_no_uid (name VARCHAR(255) NOT NULL, value TEXT)');

        try {
            $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
            $request = $request->withParsedBody(['table' => 'test_no_uid']);

            $response = $this->recordsController->fetchRecordsAction($request);

            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertTrue($body['success']);
            self::assertSame([], $body['records']);
            self::assertSame('', $body['labelField']);
            self::assertSame(0, $body['total']);
        } finally {
            $conn->executeStatement('DROP TABLE IF EXISTS test_no_uid');
        }
    }

    #[Test]
    public function fetchRecordsActionReturnsDetectedLabelField(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_FETCH_RECORDS);
        $request = $request->withParsedBody(['table' => 'tx_nrllm_task']);

        // Act
        $response = $this->recordsController->fetchRecordsAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        // labelField should be detected from TCA or common fields
        self::assertArrayHasKey('labelField', $body);
        self::assertNotEmpty($body['labelField']);
    }

    #[Test]
    public function loadRecordDataReturnsFormattedJsonData(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_LOAD_RECORD_DATA);
        $request = $request->withParsedBody([
            'table' => 'tx_nrllm_task',
            'uids' => '1',
        ]);

        // Act
        $response = $this->recordsController->loadRecordDataAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['recordCount']);

        // Verify data is properly formatted JSON
        $data = $body['data'];
        self::assertIsString($data);
        $parsedData = json_decode($data, true);
        self::assertIsArray($parsedData);
        self::assertCount(1, $parsedData);

        // Verify the record contains expected fields
        $record = $parsedData[0];
        self::assertIsArray($record);
        self::assertArrayHasKey('uid', $record);
        self::assertArrayHasKey('identifier', $record);
        self::assertArrayHasKey('name', $record);
    }

    #[Test]
    public function loadRecordDataHandlesMultipleUids(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_LOAD_RECORD_DATA);
        $request = $request->withParsedBody(
            ['table' => 'tx_nrllm_task', 'uids' => '1,2,3'],
        );

        // Act
        $response = $this->recordsController->loadRecordDataAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame(3, $body['recordCount']);
        self::assertIsString($body['data']);
        self::assertJson($body['data']);
        $records = json_decode($body['data'], true);
        self::assertIsArray($records);
        $uids = array_column($records, 'uid');
        sort($uids);
        self::assertSame([1, 2, 3], $uids);
    }

    #[Test]
    public function loadRecordDataHandlesInvalidUidsGracefully(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_TASK_LOAD_RECORD_DATA);
        $request = $request->withParsedBody([
            'table' => 'tx_nrllm_task',
            'uids' => 'abc,def',
        ]);

        // Act
        $response = $this->recordsController->loadRecordDataAction($request);

        // Assert - should return 400 for invalid UIDs
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No valid UIDs provided', $body['error']);
    }

    private function assertTaskExecutionResponse(
        int $taskUid,
        string $input,
    ): void {
        $service = $this->createMock(TaskExecutionServiceInterface::class);
        $service
            ->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(
                    static fn(Task $task): bool => $task->getUid() === $taskUid,
                ),
                $input,
                1,
            )
            ->willReturn(
                new TaskExecutionResult(
                    content: '<script>untrusted output</script>',
                    model: 'controller-oracle-model',
                    outputFormat: 'markdown',
                    usage: new UsageStatistics(11, 7, 18),
                    appliedSkills: ['editor-skill'],
                    correlationId: 'task-controller-oracle-call',
                ),
            );
        $controller = $this->createExecutionController(
            $this->taskRepository,
            $service,
            self::createStub(TaskInputResolverInterface::class),
        );
        $request = (new ServerRequest('POST', self::AJAX_NRLLM_TASK_EXECUTE))->withParsedBody(
            ['uid' => $taskUid, 'input' => $input],
        );

        $response = $controller->executeAction($request);

        self::assertSame(200, $response->getStatusCode());
        $json = (string)$response->getBody();
        self::assertJson($json);
        self::assertSame(
            [
                'success' => true,
                'content' => '<script>untrusted output</script>',
                'model' => 'controller-oracle-model',
                'outputFormat' => 'markdown',
                'usage' => [
                    'promptTokens' => 11,
                    'completionTokens' => 7,
                    'totalTokens' => 18,
                ],
                'appliedSkills' => ['editor-skill'],
                'correlationId' => 'task-controller-oracle-call',
            ],
            json_decode($json, true),
        );
    }
}
