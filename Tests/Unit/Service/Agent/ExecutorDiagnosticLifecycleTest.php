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
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\Exception\RunCancellationRequestedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunLeaseLostException;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RecordingAgentRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;

#[CoversClass(AgentRunExecutor::class)]
final class ExecutorDiagnosticLifecycleTest extends AbstractUnitTestCase
{
    #[Test]
    #[DataProvider('suspensionCases')]
    public function suspensionKeepsItsStoredOrFailedOutcome(
        string $kind,
        bool $accepted,
        string $mode,
    ): void {
        $repository                        = new RecordingAgentRunRepository();
        $repository->refuseSuspend         = !$accepted;
        $repository->refuseSuspendForInput = !$accepted;

        $logger = $this->logger($mode);
        $schema = $kind === 'input' ? [
            'type'       => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required'   => ['answer'],
        ] : [];
        $state = new SuspendedRunState(
            messages: [
                ['role' => 'user', 'content' => 'keep this transcript'],
                [
                    'role'       => 'assistant',
                    'content'    => null,
                    'tool_calls' => [
                        [
                            'id'       => 'call-7',
                            'type'     => 'function',
                            'function' => ['name' => 'ask_user', 'arguments' => '{}'],
                        ],
                    ],
                ],
            ],
            pendingCalls: [
                [
                    'id'       => 'call-7',
                    'type'     => 'function',
                    'function' => ['name' => 'ask_user', 'arguments' => []],
                ],
            ],
            iterations: 2,
            promptTokens: 13,
            completionTokens: 5,
            allowedToolNames: ['ask_user'],
            inputToolName: $kind === 'input' ? 'ask_user' : null,
            inputSchema: $schema,
        );
        $signal = $kind === 'input' ? ToolInputRequiredException::fromState($state) : ToolApprovalRequiredException::fromState($state);
        $loop   = self::createMock(ToolLoopServiceInterface::class);
        $loop->expects(self::once())->method('runLoop')->willThrowException($signal);
        $subject = new AgentRunExecutor(
            $loop,
            $this->persister($repository, $logger),
            $logger,
            self::createStub(ActingBackendUserResolverInterface::class),
        );
        $result = $this->invoke(
            fn (): AgentRunResult => $subject->executeRequest($this->request(), new AgentRunHandle(7, 'lifecycle-run'), null),
        );
        self::assertSame('lifecycle-run', $result->runUuid);
        self::assertSame([], $result->steps);
        self::assertNull($result->loopResult);
        if ($accepted) {
            self::assertSame(
                $kind === 'input' ? AgentRunOutcome::AWAITING_INPUT : AgentRunOutcome::AWAITING_APPROVAL,
                $result->outcome,
            );
            self::assertSame($state, $result->suspendedState);
            self::assertNull($result->error);
            self::assertNull($repository->finished);
            $stored = $kind === 'input' ? $repository->suspendedForInput : $repository->suspended;
            self::assertNotNull($stored);
            self::assertSame(7, $stored['runUid']);
            $payload = json_decode($stored['stateJson'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertSame(
                [
                    ['role' => 'user', 'content' => 'keep this transcript'],
                    [
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => [
                            [
                                'id'       => 'call-7',
                                'type'     => 'function',
                                'function' => ['name' => 'ask_user', 'arguments' => '{}'],
                            ],
                        ],
                    ],
                ],
                $payload['messages'],
            );
            self::assertSame(
                [
                    [
                        'id'       => 'call-7',
                        'type'     => 'function',
                        'function' => ['name' => 'ask_user', 'arguments' => []],
                    ],
                ],
                $payload['pendingCalls'],
            );
            self::assertSame(2, $payload['iterations']);
            self::assertSame(13, $payload['promptTokens']);
            self::assertSame(5, $payload['completionTokens']);
            self::assertSame($kind === 'input' ? 'ask_user' : null, $payload['inputToolName']);
            self::assertSame($schema, $payload['inputSchema']);
            self::assertSame([], $logger->records ?? []);
        } else {
            self::assertSame(AgentRunOutcome::SUSPEND_FAILED, $result->outcome);
            self::assertSame($signal, $result->error);
            self::assertNull($result->suspendedState);
            self::assertNull($repository->suspended);
            self::assertNull($repository->suspendedForInput);
            self::assertNotNull($repository->finished);
            self::assertSame('failed', $repository->finished['status']);
            self::assertSame('provider_failed', $repository->finished['terminationReason']);
            self::assertSame($signal::class, $repository->finished['errorClass']);
            self::assertSame(
                $mode === 'none' ? [] : [
                    [
                        'method'  => 'notice',
                        'message' => $kind === 'input' ? 'AgentRun was no longer running when its input suspension arrived; it was discarded' : 'AgentRun was no longer running when its suspension arrived; the suspension was discarded',
                        'context' => ['run' => 'lifecycle-run'],
                    ],
                    [
                        'method'  => 'error',
                        'message' => $kind === 'input' ? 'Agent run could not be suspended for input; no resume is possible' : 'Agent run could not be suspended for approval; no resume is possible',
                        'context' => ['run' => 'lifecycle-run'],
                    ],
                ],
                $logger->records ?? [],
                'Throwing diagnostics still attempt each original emission once, in order.',
            );
        }
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function suspensionCases(): iterable
    {
        foreach (['approval', 'input'] as $kind) {
            foreach ([true, false] as $accepted) {
                foreach (['recording', 'runtime', 'error', 'none'] as $mode) {
                    yield $kind . '/' . (int) $accepted . '/' . $mode => [$kind, $accepted, $mode];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('loggerModes')]
    public function guardrailApprovalRetainsPolicyIdentityAndExactDiagnostic(string $mode): void
    {
        $repository = new RecordingAgentRunRepository();
        $logger     = $this->logger($mode);
        $original   = new GuardrailApprovalRequiredException('SensitiveOutputPolicy', 'approval required');
        $loop       = self::createMock(ToolLoopServiceInterface::class);
        $loop->expects(self::once())->method('runLoop')->willThrowException($original);
        $subject = new AgentRunExecutor(
            $loop,
            $this->persister($repository, null),
            $logger,
            self::createStub(ActingBackendUserResolverInterface::class),
        );
        $result = $this->invoke(
            fn (): AgentRunResult => $subject->executeRequest($this->request(), new AgentRunHandle(7, 'lifecycle-run'), null),
        );
        self::assertSame(AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED, $result->outcome);
        self::assertSame($original, $result->error);
        self::assertSame('SensitiveOutputPolicy', $result->guardrailClass);
        self::assertNotNull($repository->finished);
        self::assertSame('failed', $repository->finished['status']);
        self::assertSame('approval_denied', $repository->finished['terminationReason']);
        self::assertSame(
            GuardrailApprovalRequiredException::class,
            $repository->finished['errorClass'],
        );
        self::assertSame(
            $mode === 'none' ? [] : [
                [
                    'method'  => 'warning',
                    'message' => 'Agent run blocked by guardrail',
                    'context' => ['exception' => $original, 'run' => 'lifecycle-run'],
                ],
            ],
            $logger->records ?? [],
        );
    }

    #[Test]
    #[DataProvider('ownershipCases')]
    public function bothQueuedCatchArmsLeaveAnotherOwnersRowUntouched(
        bool $policy,
        string $mode,
    ): void {
        $repository               = new RecordingAgentRunRepository();
        $repository->refuseFinish = true;

        $logger   = $this->logger($mode);
        $original = $policy ? new GuardrailApprovalRequiredException('ApprovalPolicy', 'await review') : new RuntimeException('original provider failure', 1791600500);
        $loop     = self::createMock(ToolLoopServiceInterface::class);
        $loop->expects(self::once())->method('runLoop')->willThrowException($original);
        $subject = new AgentRunExecutor(
            $loop,
            $this->persister($repository, null),
            $logger,
            self::createStub(ActingBackendUserResolverInterface::class),
        );
        $result = $this->invoke(
            fn (): AgentRunResult => $subject->executeRequest(
                $this->request(),
                new AgentRunHandle(7, 'lifecycle-run'),
                null,
                leaseOwner: 'previous-worker',
            ),
        );
        self::assertSame(AgentRunOutcome::LEASE_LOST, $result->outcome);
        self::assertSame($original, $result->error);
        self::assertSame('lifecycle-run', $result->runUuid);
        self::assertNull($repository->finished);
        self::assertNull($repository->suspended);
        self::assertNull($repository->suspendedForInput);
        self::assertSame(
            $mode === 'none' ? [] : [
                [
                    'method'  => 'notice',
                    'message' => $policy ? 'Guardrail-stopped queued run was reclaimed; leaving it to its new owner' : 'Failed queued run was reclaimed; leaving it to its new owner',
                    'context' => ['run' => 'lifecycle-run'],
                ],
            ],
            $logger->records ?? [],
        );
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function ownershipCases(): iterable
    {
        foreach ([true, false] as $policy) {
            foreach (['recording', 'runtime', 'error', 'none'] as $mode) {
                yield ($policy ? 'policy' : 'failure') . '/' . $mode => [$policy, $mode];
            }
        }
    }

    #[Test]
    #[DataProvider('loggerModes')]
    public function successfulRunPersistsUsageWithoutInventingDiagnostics(string $mode): void
    {
        $repository = new RecordingAgentRunRepository();
        $logger     = $this->logger($mode);
        $completion = new ToolLoopResult('finished answer', [], 2, false, new UsageStatistics(11, 7, 18, 0.125));
        $loop       = self::createMock(ToolLoopServiceInterface::class);
        $loop->expects(self::once())->method('runLoop')->willReturn($completion);
        $subject = new AgentRunExecutor(
            $loop,
            $this->persister($repository, null),
            $logger,
            self::createStub(ActingBackendUserResolverInterface::class),
        );
        $result = $this->invoke(
            fn (): AgentRunResult => $subject->executeRequest(
                $this->request(),
                new AgentRunHandle(7, 'lifecycle-run'),
                null,
                leaseOwner: 'owned-worker',
            ),
        );
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertSame($completion, $result->loopResult);
        self::assertNull($result->error);
        self::assertSame(
            [
                'runUid'            => 7,
                'status'            => 'completed',
                'iterations'        => 2,
                'truncated'         => false,
                'promptTokens'      => 11,
                'completionTokens'  => 7,
                'totalTokens'       => 18,
                'estimatedCost'     => 0.125,
                'errorClass'        => '',
                'terminationReason' => 'completed',
                'ownedBy'           => 'owned-worker',
            ],
            $repository->finished,
        );
        self::assertSame([], $logger->records ?? []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loggerModes(): iterable
    {
        yield 'recording logger' => ['recording'];
        yield 'RuntimeException logger' => ['runtime'];
        yield 'Error logger' => ['error'];
        yield 'no logger' => ['none'];
    }

    private function logger(string $mode): ?ExecutorDiagnosticLifecycleLogger
    {
        return match ($mode) {
            'none'    => null,
            'runtime' => new ExecutorDiagnosticLifecycleLogger(
                new RuntimeException('diagnostic RuntimeException', 1791600505),
            ),
            'error' => new ExecutorDiagnosticLifecycleLogger(new Error('diagnostic Error', 1791600506)),
            default => new ExecutorDiagnosticLifecycleLogger(),
        };
    }

    private function persister(
        RecordingAgentRunRepository $repository,
        ?ExecutorDiagnosticLifecycleLogger $logger,
    ): AgentRunPersister {
        return new AgentRunPersister(
            $repository,
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $logger,
        );
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(
            new LlmConfiguration(),
            [ChatMessage::user('execute lifecycle fixture')],
            AiActorContext::backendUser(9),
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

    #[Test]
    #[DataProvider('terminalSignals')]
    public function authoritativeTerminalSignalsRetainExactOutcomeAndDiagnostic(
        string $kind,
        string $mode,
    ): void {
        $repository = new RecordingAgentRunRepository();
        $logger     = $this->logger($mode);
        $original   = match ($kind) {
            'cancelled'        => RunCancellationRequestedException::forRun('lifecycle-run'),
            'leaseLost'        => RunLeaseLostException::forRun('lifecycle-run'),
            'providerFailed'   => new RuntimeException('original provider settlement failure', 1791600542),
            'guardrailBlocked' => new GuardrailViolationException('DenyOutputPolicy', 'response denied'),
            default            => throw new RuntimeException('Unknown fixture signal', 1791600543),
        };
        $expectedOutcome = match ($kind) {
            'cancelled'      => AgentRunOutcome::CANCELLED,
            'leaseLost'      => AgentRunOutcome::LEASE_LOST,
            'providerFailed' => AgentRunOutcome::FAILED,
            default          => AgentRunOutcome::GUARDRAIL_BLOCKED,
        };
        $expectedMethod = match ($kind) {
            'cancelled', 'leaseLost' => 'info',
            'providerFailed'         => 'error',
            default                  => 'warning',
        };
        $expectedMessage = match ($kind) {
            'cancelled'      => 'Agent run stopped cooperatively after being cancelled',
            'leaseLost'      => 'Agent run worker lost its lease; stopping without settling',
            'providerFailed' => 'Agent run failed',
            default          => 'Agent run blocked by guardrail',
        };
        $loop = self::createMock(ToolLoopServiceInterface::class);
        $loop->expects(self::once())->method('runLoop')->willThrowException($original);
        $subject = new AgentRunExecutor(
            $loop,
            $this->persister($repository, null),
            $logger,
            self::createStub(ActingBackendUserResolverInterface::class),
        );
        $result = $this->invoke(
            fn (): AgentRunResult => $subject->executeRequest(
                $this->request(),
                new AgentRunHandle(7, 'lifecycle-run'),
                null,
                leaseOwner: 'owned-worker',
            ),
        );
        self::assertSame($expectedOutcome, $result->outcome);
        self::assertSame(
            $original,
            $result->error,
            'A diagnostic failure must never replace the original control-flow or provider signal.',
        );
        self::assertSame('lifecycle-run', $result->runUuid);
        self::assertSame([], $result->steps);
        self::assertNull($result->loopResult);
        self::assertNull($result->suspendedState);
        self::assertSame(
            $kind === 'guardrailBlocked' ? 'DenyOutputPolicy' : null,
            $result->guardrailClass,
        );
        self::assertNull($repository->suspended);
        self::assertNull($repository->suspendedForInput);
        self::assertSame([], $repository->startedRuns);
        self::assertSame([], $repository->events);
        if ($kind === 'cancelled' || $kind === 'leaseLost') {
            // The repository accepts any accidental finish, so this assertion
            // detects a write by a cancelled worker or a former lease owner.
            self::assertNull(
                $repository->finished,
                'Control-flow stops must leave the already-cancelled or foreign-owned row untouched.',
            );
        } else {
            self::assertSame(
                [
                    'runUid'            => 7,
                    'status'            => 'failed',
                    'iterations'        => 0,
                    'truncated'         => false,
                    'promptTokens'      => 0,
                    'completionTokens'  => 0,
                    'totalTokens'       => 0,
                    'estimatedCost'     => 0.0,
                    'errorClass'        => $original::class,
                    'terminationReason' => $kind === 'providerFailed' ? 'provider_failed' : 'policy_denied',
                    'ownedBy'           => 'owned-worker',
                ],
                $repository->finished,
            );
        }

        $expectedContext = $kind === 'cancelled' || $kind === 'leaseLost' ? ['run' => 'lifecycle-run'] : ['exception' => $original, 'run' => 'lifecycle-run'];
        self::assertSame(
            $mode === 'none' ? [] : [
                [
                    'method'  => $expectedMethod,
                    'message' => $expectedMessage,
                    'context' => $expectedContext,
                ],
            ],
            $logger->records ?? [],
            'Each original severity invocation is attempted exactly once, even when that logger throws.',
        );
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function terminalSignals(): iterable
    {
        foreach (['cancelled', 'leaseLost', 'providerFailed', 'guardrailBlocked'] as $kind) {
            foreach (['recording', 'runtime', 'error', 'none'] as $mode) {
                yield $kind . '/' . $mode => [$kind, $mode];
            }
        }
    }
}

/**
 * A logger fixture that observes the actual severity method before it fails.
 */
final class ExecutorDiagnosticLifecycleLogger extends AbstractLogger
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
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->record('notice', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
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
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->record('log:' . (is_string($level) ? $level : 'unexpected'), $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function record(string $method, string|Stringable $message, array $context): void
    {
        $this->records[] = ['method' => $method, 'message' => (string) $message, 'context' => $context];
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
