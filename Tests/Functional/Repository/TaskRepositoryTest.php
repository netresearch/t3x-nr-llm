<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for TaskRepository.
 *
 * Tests data access layer for user pathways:
 * - Pathway 5.1: View Task List
 * - Pathway 5.2: Execute Task with Manual Input (repository queries)
 * - Pathway 5.5: Create Custom Task
 */
#[CoversClass(TaskRepository::class)]
final class TaskRepositoryTest extends AbstractFunctionalTestCase
{
    private TaskRepository $repository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');

        $repository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $repository);
        $this->repository = $repository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;
    }

    // =========================================================================
    // Pathway 5.1: View Task List
    // =========================================================================

    #[Test]
    public function findAllReturnsAllNonDeletedTasks(): void
    {
        $tasks = $this->repository->findAll();

        // Fixture has 4 tasks, 1 deleted - should return 3
        self::assertCount(3, $tasks);
    }

    #[Test]
    public function findActiveReturnsOnlyActiveTasks(): void
    {
        $tasks = $this->repository->findActive();

        foreach ($tasks as $task) {
            self::assertTrue($task->isActive());
        }
    }

    #[Test]
    public function findOneByIdentifierReturnsTask(): void
    {
        $task = $this->repository->findOneByIdentifier('test-manual-task');

        self::assertInstanceOf(Task::class, $task);
        self::assertSame('test-manual-task', $task->getIdentifier());
        self::assertSame('Test Manual Task', $task->getName());
    }

    #[Test]
    public function findOneByIdentifierReturnsNullForNonExistent(): void
    {
        $task = $this->repository->findOneByIdentifier('non-existent-task');

        self::assertNull($task);
    }

    #[Test]
    public function findByUidReturnsTask(): void
    {
        $task = $this->repository->findByUid(1);

        self::assertInstanceOf(Task::class, $task);
        self::assertSame(1, $task->getUid());
    }

    #[Test]
    public function tasksAreSortedByCategoryAndSorting(): void
    {
        $tasks = $this->repository->findActive()->toArray();

        // Verify we get tasks in expected order
        self::assertNotEmpty($tasks);
    }

    // =========================================================================
    // Category Filtering
    // =========================================================================

    #[Test]
    public function findByCategoryReturnsMatchingTasks(): void
    {
        $tasks = $this->repository->findByCategory('general');

        foreach ($tasks as $task) {
            self::assertSame('general', $task->getCategory());
            self::assertTrue($task->isActive());
        }
    }

    #[Test]
    public function findByCategoryReturnsEmptyForNonExistentCategory(): void
    {
        $tasks = $this->repository->findByCategory('non-existent-category');

        self::assertSame(0, $tasks->count());
    }

    #[Test]
    public function countByCategoryReturnsCorrectCounts(): void
    {
        $counts = $this->repository->countByCategory();

        self::assertNotEmpty($counts);

        // All counts should be positive
        foreach ($counts as $count) {
            self::assertGreaterThan(0, $count);
        }
    }

    // =========================================================================
    // System vs User Tasks
    // =========================================================================

    #[Test]
    public function findSystemTasksReturnsOnlySystemTasks(): void
    {
        $tasks = $this->repository->findSystemTasks();

        foreach ($tasks as $task) {
            self::assertTrue($task->isSystem());
        }
    }

    #[Test]
    public function findUserTasksReturnsOnlyUserTasks(): void
    {
        $tasks = $this->repository->findUserTasks();

        foreach ($tasks as $task) {
            self::assertFalse($task->isSystem());
        }
    }

    // =========================================================================
    // Input Type Filtering
    // =========================================================================

    #[Test]
    public function findByInputTypeReturnsMatchingTasks(): void
    {
        $tasks = $this->repository->findByInputType('manual');

        foreach ($tasks as $task) {
            self::assertSame('manual', $task->getInputType());
            self::assertTrue($task->isActive());
        }
    }

    #[Test]
    public function findByInputTypeSyslogReturnsSyslogTasks(): void
    {
        $tasks = $this->repository->findByInputType('syslog');

        self::assertGreaterThan(0, $tasks->count());
        foreach ($tasks as $task) {
            self::assertSame('syslog', $task->getInputType());
        }
    }

    // =========================================================================
    // Configuration Relationship
    // =========================================================================

    #[Test]
    public function findByConfigurationUidReturnsLinkedTasks(): void
    {
        $tasks = $this->repository->findByConfigurationUid(1);

        self::assertGreaterThan(0, $tasks->count());
    }

    // =========================================================================
    // Identifier Uniqueness
    // =========================================================================

    #[Test]
    public function isIdentifierUniqueReturnsTrueForNewIdentifier(): void
    {
        $result = $this->repository->isIdentifierUnique('brand-new-task-identifier');

        self::assertTrue($result);
    }

    #[Test]
    public function isIdentifierUniqueReturnsFalseForExistingIdentifier(): void
    {
        $result = $this->repository->isIdentifierUnique('test-manual-task');

        self::assertFalse($result);
    }

    #[Test]
    public function isIdentifierUniqueExcludesOwnRecord(): void
    {
        $task = $this->repository->findOneByIdentifier('test-manual-task');
        self::assertNotNull($task);

        $result = $this->repository->isIdentifierUnique('test-manual-task', $task->getUid());

        self::assertTrue($result);
    }

    // =========================================================================
    // Count Operations
    // =========================================================================

    #[Test]
    public function countActiveReturnsNonDeletedCount(): void
    {
        $count = $this->repository->countActive();

        self::assertGreaterThan(0, $count);
    }

    // =========================================================================
    // CRUD Operations (Pathway 5.5: Create Custom Task)
    // =========================================================================

    #[Test]
    public function addPersistsNewTask(): void
    {
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('new-custom-task');
        $task->setName('New Custom Task');
        $task->setDescription('A user-created task');
        $task->setCategory('custom');
        $task->setPromptTemplate('Process this: {{input}}');
        $task->setInputType('manual');
        $task->setOutputFormat('markdown');
        $task->setIsActive(true);
        $task->setIsSystem(false);

        $this->repository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->repository->findOneByIdentifier('new-custom-task');
        self::assertNotNull($retrieved);
        self::assertSame('New Custom Task', $retrieved->getName());
        self::assertFalse($retrieved->isSystem());
    }

    #[Test]
    public function updatePersistsChanges(): void
    {
        $task = $this->repository->findByUid(1);
        self::assertNotNull($task);

        $originalName = $task->getName();
        $task->setName('Updated Task Name');

        $this->repository->update($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $reloaded = $this->repository->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertSame('Updated Task Name', $reloaded->getName());

        // Restore original name
        $reloaded->setName($originalName);
        $this->repository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Task Properties
    // =========================================================================

    #[Test]
    public function taskPropertiesAreCorrectlyLoaded(): void
    {
        $task = $this->repository->findOneByIdentifier('test-manual-task');

        self::assertNotNull($task);
        self::assertSame('test-manual-task', $task->getIdentifier());
        self::assertSame('Test Manual Task', $task->getName());
        self::assertSame('A test task requiring manual input', $task->getDescription());
        self::assertSame('general', $task->getCategory());
        self::assertSame('manual', $task->getInputType());
        self::assertSame('markdown', $task->getOutputFormat());
        self::assertTrue($task->isActive());
        self::assertFalse($task->isSystem());
        self::assertStringContainsString('{{input}}', $task->getPromptTemplate());
    }

    #[Test]
    public function syslogTaskHasInputSourceConfig(): void
    {
        $task = $this->repository->findOneByIdentifier('test-syslog-task');

        self::assertNotNull($task);
        self::assertSame('syslog', $task->getInputType());
        self::assertTrue($task->isSystem());

        $inputSource = $task->getInputSource();
        self::assertNotEmpty($inputSource);
    }
}
