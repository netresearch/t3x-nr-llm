<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Task;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Service\Task\DeprecationLogReaderInterface;
use Netresearch\NrLlm\Service\Task\RecordTableReaderInterface;
use Netresearch\NrLlm\Service\Task\SystemLogReaderInterface;
use Netresearch\NrLlm\Service\Task\TaskInputResolver;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @internal Coverage for the REC #11b refactor: read-failure arms log
 *           full exception detail and surface a generic "see system
 *           log" string instead of interpolating `$e->getMessage()`
 *           into the LLM input.
 */
#[CoversClass(TaskInputResolver::class)]
final class TaskInputResolverTest extends AbstractUnitTestCase
{
    private SystemLogReaderInterface&MockObject $systemLogReader;
    private DeprecationLogReaderInterface&MockObject $deprecationLogReader;
    private RecordTableReaderInterface&MockObject $recordTableReader;
    private LoggerInterface&MockObject $logger;
    private TaskInputResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->systemLogReader      = $this->createMock(SystemLogReaderInterface::class);
        $this->deprecationLogReader = $this->createMock(DeprecationLogReaderInterface::class);
        $this->recordTableReader    = $this->createMock(RecordTableReaderInterface::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $this->subject = new TaskInputResolver(
            $this->systemLogReader,
            $this->deprecationLogReader,
            $this->recordTableReader,
            $this->logger,
        );
    }

    private static function makeTask(string $inputType, string $inputSourceJson = ''): Task
    {
        $task = new Task();
        $task->setInputType($inputType);
        $task->setInputSource($inputSourceJson);

        return $task;
    }

    #[Test]
    public function unknownInputTypeReturnsEmptyString(): void
    {
        $this->systemLogReader->expects(self::never())->method('readRecent');
        $this->deprecationLogReader->expects(self::never())->method('readTail');
        $this->recordTableReader->expects(self::never())->method('fetchAll');
        $this->logger->expects(self::never())->method(self::anything());

        self::assertSame('', $this->subject->resolve(self::makeTask('unknown')));
    }

    #[Test]
    public function deprecationLogInputDelegatesToReader(): void
    {
        $this->deprecationLogReader
            ->expects(self::once())
            ->method('readTail')
            ->willReturn('deprecation-log-content');
        $this->logger->expects(self::never())->method(self::anything());

        self::assertSame(
            'deprecation-log-content',
            $this->subject->resolve(self::makeTask(Task::INPUT_DEPRECATION_LOG)),
        );
    }

    #[Test]
    public function syslogInputFormatsRowsWithoutHittingLogger(): void
    {
        $this->systemLogReader
            ->expects(self::once())
            ->method('readRecent')
            ->with(50, true)
            ->willReturn([
                ['tstamp' => 1714521600, 'type' => 1, 'error' => 0, 'details' => 'first'],
                ['tstamp' => 1714521660, 'type' => 5, 'error' => 1, 'details' => 'second'],
            ]);
        $this->logger->expects(self::never())->method(self::anything());

        $output = $this->subject->resolve(self::makeTask(Task::INPUT_SYSLOG));

        self::assertStringContainsString('first', $output);
        self::assertStringContainsString('second', $output);
        // Two rows -> two lines.
        self::assertCount(2, explode("\n", $output));
    }

    /**
     * REC #11b regression guard: when sys_log reading throws, the resolver
     * (a) emits a `warning` log carrying the full exception, and
     * (b) returns a localized error string that does NOT contain the
     *     raw exception message — DBAL error text used to leak into the
     *     LLM prompt and onward to user-visible task output.
     */
    #[Test]
    public function syslogReadFailureLogsWarningAndReturnsGenericMessage(): void
    {
        $sensitive = 'SQLSTATE[42S02]: Base table or view not found: 1146 Table sys_log_secret missing';
        $this->systemLogReader
            ->expects(self::once())
            ->method('readRecent')
            ->willThrowException(new RuntimeException($sensitive));

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('sys_log read failed'),
                self::callback(static function (array $context) use ($sensitive): bool {
                    self::assertArrayHasKey('exception', $context);
                    self::assertArrayHasKey('taskUid', $context);
                    self::assertArrayHasKey('limit', $context);
                    self::assertArrayHasKey('errorOnly', $context);
                    self::assertSame(50, $context['limit']);
                    self::assertTrue($context['errorOnly']);
                    self::assertInstanceOf(RuntimeException::class, $context['exception']);
                    self::assertSame($sensitive, $context['exception']->getMessage());

                    return true;
                }),
            );

        $output = $this->subject->resolve(self::makeTask(Task::INPUT_SYSLOG));

        self::assertStringNotContainsString('SQLSTATE', $output);
        self::assertStringNotContainsString('sys_log_secret', $output);
        self::assertStringNotContainsString($sensitive, $output);
        self::assertSame('Error reading sys_log. See system log for details.', $output);
    }

    #[Test]
    public function tableInputReturnsNotConfiguredWhenTableEmpty(): void
    {
        $this->recordTableReader->expects(self::never())->method('fetchAll');
        $this->logger->expects(self::never())->method(self::anything());

        $output = $this->subject->resolve(self::makeTask(Task::INPUT_TABLE, '{"limit":10}'));

        self::assertSame('No table configured.', $output);
    }

    #[Test]
    public function tableInputJsonEncodesRowsWithoutHittingLogger(): void
    {
        $this->recordTableReader
            ->expects(self::once())
            ->method('fetchAll')
            ->with('be_users', 5)
            ->willReturn([
                ['uid' => 1, 'username' => 'admin'],
                ['uid' => 2, 'username' => 'editor'],
            ]);
        $this->logger->expects(self::never())->method(self::anything());

        $output = $this->subject->resolve(self::makeTask(
            Task::INPUT_TABLE,
            '{"table":"be_users","limit":5}',
        ));

        self::assertJson($output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
    }

    /**
     * REC #11b regression guard for the table branch — same contract as
     * the syslog version: warning logged with full context, generic
     * localized output, no `$e->getMessage()` leak.
     */
    #[Test]
    public function tableReadFailureLogsWarningAndReturnsGenericMessage(): void
    {
        $sensitive = 'SQLSTATE[HY000]: General error: 145 Table./var/lib/mysql/secret_table./broken';
        $this->recordTableReader
            ->expects(self::once())
            ->method('fetchAll')
            ->with('be_users', 50)
            ->willThrowException(new RuntimeException($sensitive));

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('table read failed'),
                self::callback(static function (array $context) use ($sensitive): bool {
                    self::assertArrayHasKey('exception', $context);
                    self::assertSame('be_users', $context['table'] ?? null);
                    self::assertSame(50, $context['limit'] ?? null);
                    $exception = $context['exception'];
                    self::assertInstanceOf(RuntimeException::class, $exception);
                    self::assertSame($sensitive, $exception->getMessage());

                    return true;
                }),
            );

        $output = $this->subject->resolve(self::makeTask(
            Task::INPUT_TABLE,
            '{"table":"be_users"}',
        ));

        self::assertStringNotContainsString('SQLSTATE', $output);
        self::assertStringNotContainsString('secret_table', $output);
        self::assertStringNotContainsString($sensitive, $output);
        self::assertSame('Error reading table. See system log for details.', $output);
    }

    /**
     * Picker-policy rejection (table on the exclusion list) goes through
     * `InvalidArgumentException`. The resolver routes that to an `info`
     * log (it's a policy decision, not a runtime error) and surfaces the
     * same generic table-read error to the user.
     */
    #[Test]
    public function tableExcludedByPickerPolicyLogsInfoAndReturnsGenericMessage(): void
    {
        $this->recordTableReader
            ->expects(self::once())
            ->method('fetchAll')
            ->with('be_groups', 50)
            ->willThrowException(new InvalidArgumentException("Table 'be_groups' is not allowed for record selection"));

        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with(
                self::stringContains('rejected by record-picker policy'),
                self::callback(static function (array $context): bool {
                    self::assertSame('be_groups', $context['table'] ?? null);
                    self::assertInstanceOf(InvalidArgumentException::class, $context['exception']);

                    return true;
                }),
            );

        $output = $this->subject->resolve(self::makeTask(
            Task::INPUT_TABLE,
            '{"table":"be_groups"}',
        ));

        self::assertSame('Error reading table. See system log for details.', $output);
    }
}
