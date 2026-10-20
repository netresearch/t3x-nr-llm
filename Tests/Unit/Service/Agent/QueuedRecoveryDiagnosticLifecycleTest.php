<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Closure;
use Error;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Agent\QueuedRunFailureRecovery;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RecordingAgentRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

#[CoversClass(QueuedRunFailureRecovery::class)]
final class QueuedRecoveryDiagnosticLifecycleTest extends AbstractUnitTestCase
{
    #[Test]
    #[DataProvider('recoveryCases')]
    public function recoveryPreservesRetryDecisionOriginalFailureAndDispatch(
        string $scenario,
        string $mode,
    ): void {
        $repository = new RecordingAgentRunRepository();
        $logger     = $this->logger($mode);
        $original   = $scenario === 'nonretryable' || $scenario === 'nonretryable-refused' ? new RuntimeException('original nonretryable failure', 1791600501) : new ProviderConnectionException(
            'original connection failure',
            1791600502,
        );
        $internal               = $scenario === 'dispatch-error' ? new Error('dispatch internal error', 1791600503) : new RuntimeException('dispatch internal failure', 1791600504);
        $count                  = $scenario === 'exhausted' || $scenario === 'exhausted-refused' ? 3 : ($scenario === 'dispatch-first' ? 0 : 2);
        $repository->findResult = $this->storedRun(
            $count,
            $scenario === 'fenced' ? 'non_idempotent_write' : '',
        );
        $repository->refuseRequeue = $scenario === 'requeue-refused';
        $repository->refuseFinish  = $scenario === 'nonretryable-refused' || $scenario === 'exhausted-refused';

        $dispatches       = [];
        $bus              = self::createMock(MessageBusInterface::class);
        $dispatchExpected = in_array(
            $scenario,
            [
                'dispatch-runtime',
                'dispatch-error',
                'dispatch-first',
                'dispatch-later',
            ],
            true,
        );
        if ($dispatchExpected) {
            $bus
                ->expects(self::once())
                ->method('dispatch')
                ->willReturnCallback(
                    static function (
                        object $message,
                        array $stamps = [],
                    ) use (&$dispatches, $scenario, $internal): Envelope {
                        $dispatches[] = ['message' => $message, 'stamps' => $stamps];
                        if ($scenario === 'dispatch-runtime' || $scenario === 'dispatch-error') {
                            throw $internal;
                        }

                        return new Envelope($message);
                    },
                );
        } else {
            $bus->expects(self::never())->method('dispatch');
        }

        $subject = new QueuedRunFailureRecovery(
            $this->persister($repository, null),
            $logger,
            $bus,
        );
        $steps = [
            new RunStep(
                kind: RunStep::KIND_REQUEST,
                round: 2,
                durationMs: 0.0,
                messagesSent: [['role' => 'user', 'content' => 'prior user request']],
            ),
            new RunStep(
                kind: RunStep::KIND_LLM,
                round: 2,
                durationMs: 17.25,
                content: 'prior visible provider response',
            ),
        ];
        $result = $this->invoke(
            fn (): AgentRunResult => $subject->recover(
                new AgentRunHandle(7, 'lifecycle-run'),
                'lifecycle-run',
                'owned-worker',
                $original,
                $steps,
            ),
        );
        self::assertSame($original, $result->error);
        self::assertSame('lifecycle-run', $result->runUuid);
        self::assertSame($steps, $result->steps);
        self::assertSame($dispatchExpected ? 1 : 0, count($dispatches));
        if ($dispatchExpected) {
            $message = $dispatches[0]['message'];
            self::assertInstanceOf(AgentRunQueuedMessage::class, $message);
            self::assertSame('lifecycle-run', $message->runUuid);
            self::assertCount(1, $dispatches[0]['stamps']);
            $delay = $dispatches[0]['stamps'][0];
            self::assertInstanceOf(DelayStamp::class, $delay);
            self::assertSame(
                $scenario === 'dispatch-first' ? 30000 : 120000,
                $delay->getDelay(),
            );
            self::assertSame(
                [['runUid' => 7, 'claimedBy' => 'owned-worker']],
                $repository->requeues,
            );
        } else {
            self::assertSame([], $repository->requeues);
        }

        $expectedRecords = [];
        if ($scenario === 'dispatch-first' || $scenario === 'dispatch-later') {
            self::assertSame(AgentRunOutcome::REQUEUED, $result->outcome);
            self::assertNull($repository->finished);
        } elseif (in_array(
            $scenario,
            ['requeue-refused', 'nonretryable-refused', 'exhausted-refused'],
            true,
        )) {
            self::assertSame(AgentRunOutcome::LEASE_LOST, $result->outcome);
            self::assertNull($repository->finished);
            $expectedRecords = [
                [
                    'method'  => 'notice',
                    'message' => $scenario === 'requeue-refused' ? 'Queued agent run could not be requeued (ownership lost or already terminal); leaving it untouched' : 'Queued agent run could not be dead-lettered (ownership lost or already terminal); leaving it untouched',
                    'context' => ['run' => 'lifecycle-run'],
                ],
            ];
        } else {
            self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
            self::assertNotNull($repository->finished);
            self::assertSame('failed', $repository->finished['status']);
            self::assertSame(
                $original::class,
                $repository->finished['errorClass'],
            );
            self::assertSame('owned-worker', $repository->finished['ownedBy']);
            self::assertSame(
                $scenario === 'nonretryable' || $scenario === 'fenced' ? 'not_retryable' : 'retries_exhausted',
                $repository->finished['terminationReason'],
            );
            $expectedRecords = [
                match ($scenario) {
                    'nonretryable' => [
                        'method'  => 'warning',
                        'message' => 'Queued agent run failed with a non-retryable error; dead-lettered',
                        'context' => ['run' => 'lifecycle-run', 'class' => 'unknown'],
                    ],
                    'fenced' => [
                        'method'  => 'warning',
                        'message' => 'Queued agent run failed mid non-idempotent write; dead-lettered instead of retried (ADR-111)',
                        'context' => ['run' => 'lifecycle-run'],
                    ],
                    'exhausted' => [
                        'method'  => 'warning',
                        'message' => 'Queued agent run exhausted its retry budget; dead-lettered',
                        'context' => ['run' => 'lifecycle-run', 'requeueCount' => 3],
                    ],
                    default => [
                        'method'  => 'error',
                        'message' => 'Queued agent run recovery failed; dead-lettering',
                        'context' => ['run' => 'lifecycle-run', 'exception' => $internal],
                    ],
                },
            ];
        }

        self::assertSame(
            $mode === 'none' ? [] : $expectedRecords,
            $logger->records ?? [],
            'Optional emission attempts keep their original method, contents and order.',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function recoveryCases(): iterable
    {
        foreach ([
            'nonretryable',
            'nonretryable-refused',
            'fenced',
            'exhausted',
            'exhausted-refused',
            'requeue-refused',
            'dispatch-runtime',
            'dispatch-error',
            'dispatch-first',
            'dispatch-later',
        ] as $scenario) {
            foreach (['recording', 'runtime', 'error', 'none'] as $mode) {
                yield $scenario . '/' . $mode => [$scenario, $mode];
            }
        }
    }

    private function logger(
        string $mode,
    ): ?QueuedRecoveryDiagnosticLifecycleLogger {
        return match ($mode) {
            'none'    => null,
            'runtime' => new QueuedRecoveryDiagnosticLifecycleLogger(
                new RuntimeException('diagnostic RuntimeException', 1791600505),
            ),
            'error' => new QueuedRecoveryDiagnosticLifecycleLogger(
                new Error('diagnostic Error', 1791600506),
            ),
            default => new QueuedRecoveryDiagnosticLifecycleLogger(),
        };
    }

    private function persister(
        RecordingAgentRunRepository $repository,
        ?QueuedRecoveryDiagnosticLifecycleLogger $logger,
    ): AgentRunPersister {
        return new AgentRunPersister(
            $repository,
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $logger,
        );
    }

    /**
     * @param Closure(): AgentRunResult $operation
     */
    private function invoke(Closure $operation): AgentRunResult
    {
        $caught = null;
        $result = null;
        try {
            $result = $operation();
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertNull(
            $caught,
            'Optional logger Throwable must not escape an authoritative lifecycle result.',
        );
        self::assertInstanceOf(AgentRunResult::class, $result);

        return $result;
    }

    private function storedRun(int $count, string $effect): AgentRun
    {
        return new AgentRun(
            uid: 7,
            uuid: 'lifecycle-run',
            status: 'running',
            configurationUid: 0,
            configurationIdentifier: '',
            beUser: 9,
            iterations: 0,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 1,
            finishedAt: 0,
            crdate: 1,
            claimedBy: 'owned-worker',
            leaseExpires: 9999999999,
            requeueCount: $count,
            pendingEffect: $effect,
        );
    }
}

/**
 * A logger fixture that observes the actual severity method before it fails.
 */
final class QueuedRecoveryDiagnosticLifecycleLogger extends AbstractLogger
{
    /** @var list<array{method: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function __construct(private readonly ?Throwable $failure = null) {}

    /**
     * @param array<array-key, mixed> $context
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->record('info', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function notice(
        string|Stringable $message,
        array $context = [],
    ): void {
        $this->record('notice', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function warning(
        string|Stringable $message,
        array $context = [],
    ): void {
        $this->record('warning', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->record('error', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(
        $level,
        string|Stringable $message,
        array $context = [],
    ): void {
        $this->record(
            'log:' . (is_string($level) ? $level : 'unexpected'),
            $message,
            $context,
        );
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function record(
        string $method,
        string|Stringable $message,
        array $context,
    ): void {
        $this->records[] = [
            'method'  => $method,
            'message' => (string) $message,
            'context' => $context,
        ];
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
