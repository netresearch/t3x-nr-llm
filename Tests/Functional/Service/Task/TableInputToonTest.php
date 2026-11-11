<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Task;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Task\DeprecationLogReaderInterface;
use Netresearch\NrLlm\Service\Task\RecordTableReaderInterface;
use Netresearch\NrLlm\Service\Task\SystemLogReaderInterface;
use Netresearch\NrLlm\Service\Task\TableInputEncoder;
use Netresearch\NrLlm\Service\Task\TaskExecutionService;
use Netresearch\NrLlm\Service\Task\TaskInputResolver;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * The prompt a table task sends (ADR-286): TOON when the task's input source
 * says `"format":"toon"` and the rows are one table, JSON otherwise. The rows
 * come from the real database through the resolver the container wires,
 * except for the non-uniform case: `SELECT *` rows always share their
 * columns, so that case puts a stub reader behind the real resolver.
 */
#[CoversClass(TaskInputResolver::class)]
#[CoversClass(TableInputEncoder::class)]
final class TableInputToonTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('TableInputToon.csv');
    }

    #[Test]
    public function aTableTaskAskingForToonSendsItsRowsAsToon(): void
    {
        $prompt = $this->promptOf($this->task(231), $this->resolverFromContainer());

        self::assertStringStartsWith('Summarise these categories: ' . TableInputEncoder::TOON_NOTE . "\n```toon\n[2]{uid,pid,", $prompt);
        // One row per line; the column order is the table's.
        self::assertMatchesRegularExpression('/^  (?=[^\n]*,"Apples, pears",)(?=[^\n]*,Fruit,)/m', $prompt);
        self::assertMatchesRegularExpression('/^  (?=[^\n]*,Leeks,)(?=[^\n]*,Vegetables,)/m', $prompt);
        self::assertStringEndsWith("\n```", $prompt);
        self::assertStringNotContainsString('"title":', $prompt);
    }

    #[Test]
    public function aTableTaskWithoutTheOptionStillSendsJson(): void
    {
        $prompt = $this->promptOf($this->task(232), $this->resolverFromContainer());

        self::assertStringStartsWith("Summarise these categories: [\n    {\n", $prompt);
        self::assertStringContainsString('"title": "Fruit"', $prompt);
        self::assertStringNotContainsString(TableInputEncoder::TOON_NOTE, $prompt);
    }

    #[Test]
    public function rowsThatAreNotOneTableStayJsonEvenWhenToonIsAsked(): void
    {
        $rows = [
            ['uid' => 1, 'title' => 'Fruit'],
            ['uid' => 2, 'header' => 'Vegetables'],
        ];
        $reader = self::createStub(RecordTableReaderInterface::class);
        $reader->method('fetchAll')->willReturn($rows);

        $resolver = new TaskInputResolver(
            $this->getService(SystemLogReaderInterface::class),
            $this->getService(DeprecationLogReaderInterface::class),
            $reader,
            self::createStub(LoggerInterface::class),
            $this->getService(TableInputEncoder::class),
        );

        $prompt = $this->promptOf($this->task(231), $resolver);

        self::assertSame('Summarise these categories: ' . json_encode($rows, JSON_PRETTY_PRINT), $prompt);
    }

    private function resolverFromContainer(): TaskInputResolverInterface
    {
        return $this->getService(TaskInputResolverInterface::class);
    }

    private function task(int $uid): Task
    {
        $task = $this->getService(TaskRepository::class)->findByUid($uid);
        self::assertInstanceOf(Task::class, $task);

        return $task;
    }

    /**
     * Run the task through the execution service and return the prompt that
     * reaches the provider call.
     */
    private function promptOf(Task $task, TaskInputResolverInterface $resolver): string
    {
        $prompt  = null;
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturnArgument(0);
        $manager->expects(self::once())->method('completeWithConfiguration')->willReturnCallback(
            static function (string $sent, LlmConfiguration $configuration) use (&$prompt): CompletionResponse {
                $prompt = $sent;

                return new CompletionResponse(
                    content: 'done',
                    model: 'gpt-4o',
                    usage: new UsageStatistics(1, 1, 2),
                    provider: 'openai',
                );
            },
        );

        $service = new TaskExecutionService(
            $manager,
            new SkillInjectionService(new SkillComposer(), self::createStub(LoggerInterface::class)),
        );
        $service->execute($task, $resolver->resolve($task));

        self::assertIsString($prompt);

        return $prompt;
    }
}
