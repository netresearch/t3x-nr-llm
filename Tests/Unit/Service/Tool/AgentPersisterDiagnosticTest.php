<?php

/* Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Closure;
use Error;
use LogicException;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;

#[CoversClass(AgentRunPersister::class)]
final class AgentPersisterDiagnosticTest extends AbstractUnitTestCase
{
    #[Test]
    #[DataProvider('storageFailureCases')]
    public function storageFailureRetainsItsExactFallbackAndDiagnostic(
        string $operation,
        string $repositoryMethod,
        mixed $expected,
        string $message,
        string $loggerMode,
    ): void {
        $storageError = new RuntimeException('storage unavailable');
        $repository = self::createMock(AgentRunRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method($repositoryMethod)
            ->willThrowException($storageError);
        $run = $this->storedRun();
        if ($operation === 'cancel') {
            $repository
                ->expects(self::once())
                ->method('findByUuid')
                ->with('stored-run')
                ->willReturn($run);
        }

        $logger = $this->loggerFor($loggerMode);
        $subject = new AgentRunPersister(
            $repository,
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $logger,
        );
        $handle = new AgentRunHandle(7, 'stored-run');
        $handle->sequence = 4;

        $failure = null;
        $actual = 'not returned';
        try {
            $actual = $this->invoke($subject, $operation, $run, $handle);
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($failure, 'Optional diagnostics must preserve the storage fallback.');
        self::assertSame($expected, $actual);
        self::assertSame(
            4,
            $handle->sequence,
            'A failed durable event must not advance its sequence.',
        );
        if ($logger instanceof RecordingLogger) {
            self::assertSame(
                [
                    [
                        'level' => 'warning',
                        'message' => $message,
                        'context' => ['exception' => $storageError],
                    ],
                ],
                $logger->records,
            );
        }
    }

    /**
     * @return iterable<string,array{string,string,mixed,string,string}>
     */
    public static function storageFailureCases(): iterable
    {
        $cases = [
            [
                'begin',
                'startRun',
                null,
                'AgentRun could not be started; the run will not be persisted',
            ],
            ['enqueue', 'enqueueRun', null, 'AgentRun could not be enqueued'],
            ['claimQueued', 'claimQueued', false, 'AgentRun could not be claimed for execution'],
            [
                'renewLease',
                'renewLease',
                false,
                'AgentRun lease could not be renewed; the worker will treat it as lost',
            ],
            [
                'markPendingEffect',
                'markPendingEffect',
                false,
                'AgentRun pending-effect fence could not be written; the worker will treat the lease as lost',
            ],
            ['requeue', 'requeue', false, 'AgentRun could not be requeued for retry'],
            ['recordStep', 'recordEvent', false, 'AgentRun step could not be persisted'],
            ['settleCompleted', 'finishRun', false, 'AgentRun could not be settled'],
            ['settleFailed', 'finishRun', false, 'AgentRun could not be settled'],
            ['settlePolicyStopped', 'finishRun', false, 'AgentRun could not be settled'],
            ['settleDeadLettered', 'finishRun', false, 'AgentRun could not be settled'],
            ['settleCancelled', 'finishRun', false, 'AgentRun could not be settled'],
            ['cancel', 'finishRun', false, 'AgentRun could not be cancelled'],
            ['settleIfWaiting', 'settleIfWaiting', false, 'A waiting AgentRun could not be settled'],
            ['suspend', 'suspendRun', false, 'AgentRun could not be suspended'],
            [
                'suspendForInput',
                'suspendRunForInput',
                false,
                'AgentRun could not be suspended for input',
            ],
            ['findRun', 'findByUuid', null, 'AgentRun could not be loaded'],
            ['findAwaitingRuns', 'findAwaiting', null, 'Awaiting agent runs could not be loaded'],
            [
                'findRecentTerminalRuns',
                'findRecentTerminal',
                null,
                'Recent terminal agent runs could not be loaded',
            ],
            ['claimResume', 'claimForResume', false, 'AgentRun could not be claimed for resume'],
            [
                'claimResumeFromInput',
                'claimForResumeFromInput',
                false,
                'AgentRun could not be claimed for input resume',
            ],
            [
                'resumeHandle',
                'maxEventSequence',
                null,
                'AgentRun event position could not be determined; the resume is refused',
            ],
            [
                'recordApproval',
                'recordEvent',
                false,
                'AgentRun approval decision could not be persisted',
            ],
            ['findEvents', 'findEvents', [], 'AgentRun events could not be loaded'],
            [
                'findApprovalDeciders',
                'findApprovalDeciders',
                [],
                'AgentRun approval deciders could not be loaded',
            ],
            ['findStaleRunning', 'findStaleRunning', [], 'Stale agent runs could not be loaded'],
            ['requeueStale', 'requeueStale', false, 'Stale agent run could not be requeued'],
            [
                'settleDeadLetteredStale',
                'deadLetterStale',
                false,
                'Stale agent run could not be dead-lettered',
            ],
        ];
        foreach ($cases as [$operation, $repositoryMethod, $expected, $message]) {
            foreach (['recording', 'runtime', 'error', 'absent'] as $mode) {
                yield $operation . '/' . $mode => [$operation, $repositoryMethod, $expected, $message, $mode];
            }
        }
    }

    #[Test]
    #[DataProvider('loggerModes')]
    public function inputAuditFailureRemainsBestEffortWithoutAdvancingSequence(
        string $mode,
    ): void {
        $original = new RuntimeException('input audit unavailable');
        $repository = self::createMock(AgentRunRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('recordEvent')
            ->with(7, 4, 'input', 0, 0.0, '{"submittedBy":9}')
            ->willThrowException($original);
        $logger = $this->loggerFor($mode);
        $subject = new AgentRunPersister(
            $repository,
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $logger,
        );
        $handle = new AgentRunHandle(7, 'stored-run');
        $handle->sequence = 4;

        $caught = null;
        try {
            $subject->recordInput($handle, 9);
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertNull($caught);
        self::assertSame(4, $handle->sequence);
        if ($logger instanceof RecordingLogger) {
            self::assertSame(
                [
                    [
                        'level' => 'warning',
                        'message' => 'AgentRun input submission could not be persisted',
                        'context' => ['exception' => $original],
                    ],
                ],
                $logger->records,
            );
        }
    }

    #[Test]
    public function invalidWaitingTransitionRemainsAnExplicitCallerError(): void
    {
        $original = new InvalidArgumentException('invalid waiting transition');
        $repository = self::createMock(AgentRunRepositoryInterface::class);
        $repository->expects(self::once())->method('settleIfWaiting')->willThrowException($original);
        $logger = new RecordingLogger();
        $subject = new AgentRunPersister(
            $repository,
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $logger,
        );
        $caught = null;
        try {
            $subject->settleIfWaiting(
                $this->storedRun(),
                [AgentRunStatus::RUNNING],
                AgentRunStatus::COMPLETED,
                AgentRunTerminationReason::COMPLETED,
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertSame($original, $caught);
        self::assertSame([], $logger->records);
    }

    #[Test]
    #[DataProvider('refusedSuspensionCases')]
    public function refusedSuspensionRetainsFalseAndOriginalNotice(
        bool $input,
        string $mode,
    ): void {
        $repository = self::createMock(AgentRunRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method($input ? 'suspendRunForInput' : 'suspendRun')
            ->willReturn(false);
        $logger = $this->loggerFor($mode);
        $subject = new AgentRunPersister(
            $repository,
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $logger,
        );
        $handle = new AgentRunHandle(7, 'stored-run');
        $state = new SuspendedRunState([], [], 1, 2, 3);
        $caught = null;
        $result = null;
        try {
            $result = $input ? $subject->suspendForInput($handle, $state) : $subject->suspend($handle, $state);
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertNull($caught);
        self::assertFalse($result);
        if ($logger instanceof RecordingLogger) {
            self::assertSame(
                [
                    [
                        'level' => 'notice',
                        'message' => $input ? 'AgentRun was no longer running when its input suspension arrived; it was discarded' : 'AgentRun was no longer running when its suspension arrived; the suspension was discarded',
                        'context' => ['run' => 'stored-run'],
                    ],
                ],
                $logger->records,
            );
        }
    }

    /**
     * @return iterable<string,array{bool,string}>
     */
    public static function refusedSuspensionCases(): iterable
    {
        foreach ([false, true] as $input) {
            foreach (['recording', 'runtime', 'error', 'absent'] as $mode) {
                yield (int)$input . '/' . $mode => [$input, $mode];
            }
        }
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function loggerModes(): iterable
    {
        foreach (['recording', 'runtime', 'error', 'absent'] as $mode) {
            yield $mode => [$mode];
        }
    }

    private function invoke(
        AgentRunPersister $subject,
        string $operation,
        AgentRun $run,
        AgentRunHandle $handle,
    ): mixed {
        $state = new SuspendedRunState([], [], 1, 2, 3);
        $original = new RuntimeException('original run failure');
        return match ($operation) {
            'begin' => $subject->begin(new LlmConfiguration(), 9),
            'enqueue' => $subject->enqueue(new LlmConfiguration(), 9, '{}'),
            'claimQueued' => $subject->claimQueued($run, 'owner', 100),
            'renewLease' => $subject->renewLease($handle, 'owner', 100),
            'markPendingEffect' => $subject->markPendingEffect($handle, 'owner', 'non_idempotent_write', 100),
            'requeue' => $subject->requeue($handle, 'owner'),
            'recordStep' => $subject->recordStep($handle, new RunStep(RunStep::KIND_LLM, 1, 0.0, content: 'answer')),
            'settleCompleted' => $subject->settleCompleted(
                $handle,
                new ToolLoopResult('answer', [], 1, false, new UsageStatistics(0, 0, 0)),
                'owner',
            ),
            'settleFailed' => $subject->settleFailed($handle, $original, 'owner'),
            'settlePolicyStopped' => $subject->settlePolicyStopped(
                $handle,
                $original,
                AgentRunTerminationReason::POLICY_DENIED,
                'owner',
            ),
            'settleDeadLettered' => $subject->settleDeadLettered(
                $handle,
                $original,
                AgentRunTerminationReason::NOT_RETRYABLE,
                'owner',
            ),
            'settleCancelled' => $subject->settleCancelled($handle, 'owner'),
            'cancel' => $subject->cancel('stored-run'),
            'settleIfWaiting' => $subject->settleIfWaiting(
                $run,
                [AgentRunStatus::WAITING_FOR_APPROVAL],
                AgentRunStatus::CANCELLED,
                AgentRunTerminationReason::CANCELLED,
            ),
            'suspend' => $subject->suspend($handle, $state),
            'suspendForInput' => $subject->suspendForInput($handle, $state),
            'findRun' => $subject->findRun('stored-run'),
            'findAwaitingRuns' => $subject->findAwaitingRuns(5, 9),
            'findRecentTerminalRuns' => $subject->findRecentTerminalRuns(5, 9),
            'claimResume' => $subject->claimResume($run, 'owner', 100),
            'claimResumeFromInput' => $subject->claimResumeFromInput($run, 'owner', 100),
            'resumeHandle' => $subject->resumeHandle($run),
            'recordApproval' => $subject->recordApproval($handle, true, 9),
            'findEvents' => $subject->findEvents(7, 3),
            'findApprovalDeciders' => $subject->findApprovalDeciders([7]),
            'findStaleRunning' => $subject->findStaleRunning(100, 5),
            'requeueStale' => $subject->requeueStale($run, 100),
            'settleDeadLetteredStale' => $subject->settleDeadLetteredStale($run, 100, AgentRunTerminationReason::NOT_RETRYABLE),
            default => throw new LogicException('Unknown diagnostic fixture operation: ' . $operation, 15505100),
        };
    }

    private function storedRun(): AgentRun
    {
        return new AgentRun(
            7,
            'stored-run',
            'running',
            3,
            'configuration',
            9,
            0,
            false,
            0,
            0,
            0,
            0.0,
            '',
            '',
            1,
            0,
            1,
        );
    }

    private function loggerFor(string $mode): ?LoggerInterface
    {
        if ($mode === 'recording') {
            return new RecordingLogger();
        }

        if ($mode === 'absent') {
            return null;
        }

        $error = $mode === 'error' ? new Error('optional diagnostic Error') : new RuntimeException('optional diagnostic exception');
        return new class ($error) extends AbstractLogger {
            public function __construct(private readonly Throwable $error) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw $this->error;
            }
        };
    }

    /**
     * @param list<array{level:mixed,message:string,context:array<array-key,mixed>}> $attempts
     */
    private function refusalLogger(string $mode, array &$attempts): ?LoggerInterface
    {
        if ($mode === 'absent') {
            return null;
        }

        $failure = match ($mode) {
            'recording' => null,
            'runtime' => new RuntimeException('notice logger unavailable'),
            'error' => new Error('notice logger error'),
            default => throw new LogicException('Unknown logger fixture mode', 15505101),
        };
        $record = static function (mixed $level, string $message, array $context) use (&$attempts): void {
            $attempts[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };
        return new class ($record, $failure) extends AbstractLogger {
            /**
             * @param Closure(mixed,string,array<array-key,mixed>):void $record
             */
            public function __construct(
                private readonly Closure $record,
                private readonly ?Throwable $failure,
            ) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                ($this->record)($level, (string)$message, $context);
                if ($this->failure instanceof Throwable) {
                    throw $this->failure;
                }
            }
        };
    }

    #[Test]
    #[DataProvider('refusedTerminalCases')]
    public function refusedTerminalWriteRetainsFalseAndOnlyOriginalNotice(
        string $operation,
        string $status,
        string $reason,
        string $loggerMode,
    ): void {
        $repository = self::createMock(AgentRunRepositoryInterface::class);
        $repository->expects(self::once())->method('finishRun')->willReturn(false);
        $attempts = [];
        $subject = new AgentRunPersister(
            $repository,
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $this->refusalLogger($loggerMode, $attempts),
        );
        $failure = null;
        $actual = null;
        try {
            $actual = $this->invoke(
                $subject,
                $operation,
                $this->storedRun(),
                new AgentRunHandle(7, 'stored-run'),
            );
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($failure);
        self::assertFalse($actual);
        self::assertSame(
            $loggerMode === 'absent' ? [] : [
                [
                    'level' => 'notice',
                    'message' => 'AgentRun was not settled by this call (already terminal or ownership lost)',
                    'context' => ['run' => 'stored-run', 'status' => $status, 'reason' => $reason],
                ],
            ],
            $attempts,
            'Diagnostic failure must not fabricate a storage-failure warning or retry reporting.',
        );
    }

    /**
     * @return iterable<string,array{string,string,string,string}>
     */
    public static function refusedTerminalCases(): iterable
    {
        foreach ([
            ['settleCompleted', 'completed', 'completed'],
            ['settleFailed', 'failed', 'provider_failed'],
            ['settlePolicyStopped', 'failed', 'policy_denied'],
            ['settleDeadLettered', 'failed', 'not_retryable'],
            ['settleCancelled', 'cancelled', 'cancelled'],
        ] as [$operation, $status, $reason]) {
            foreach (['recording', 'runtime', 'error', 'absent'] as $mode) {
                yield $operation . '/' . $mode => [$operation, $status, $reason, $mode];
            }
        }
    }
}
