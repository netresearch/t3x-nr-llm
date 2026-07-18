<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\RequiresApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceTest extends TestCase
{
    #[Test]
    public function returnsContentWhenNoToolCalls(): void
    {
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('the answer'));
        $mgr->expects(self::never())->method('chatWithConfiguration');

        // A registered tool keeps the loop on the tools path (an empty registry
        // would short-circuit to a plain completion — covered separately).
        $service = $this->service($mgr, new ToolRegistry([new FakeTool('noop')]));
        $result  = $service->runLoop([$this->userTurn('hi')], new LlmConfiguration(), null);

        self::assertSame('the answer', $result->finalContent);
        self::assertSame([], $result->trace);
        self::assertSame(1, $result->iterations);
        self::assertFalse($result->truncated);
    }

    #[Test]
    public function executesToolAndFeedsResultThenFinishes(): void
    {
        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'fetch_logs', [])]),
            $this->response('all done'),
        ];
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback($this->queueCallback($queue));

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs', 'LOGS')]));
        $result  = $service->runLoop([$this->userTurn('show logs')], new LlmConfiguration(), null);

        self::assertCount(1, $result->trace);
        self::assertSame('fetch_logs', $result->trace[0]->name);
        self::assertSame('LOGS', $result->trace[0]->result);
        self::assertFalse($result->trace[0]->isError);
        self::assertSame('all done', $result->finalContent);
        self::assertSame(2, $result->iterations);
        self::assertFalse($result->truncated);
    }

    #[Test]
    public function unknownToolYieldsErrorResultNotCrash(): void
    {
        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'nope', [])]),
            $this->response('recovered'),
        ];
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback($this->queueCallback($queue));

        // The registry holds a different tool so the loop stays on the tools
        // path; the model then requests the unregistered name "nope", which
        // registry->get() resolves to null (the unknown-tool branch).
        $service = $this->service($mgr, new ToolRegistry([new FakeTool('real_tool')]));
        $result  = $service->runLoop([$this->userTurn('do it')], new LlmConfiguration(), null);

        self::assertCount(1, $result->trace);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringStartsWith('Error: unknown tool', $result->trace[0]->result);
        self::assertStringContainsString('"nope"', $result->trace[0]->result);
        self::assertSame('recovered', $result->finalContent);
    }

    #[Test]
    public function toolExecuteThrowsIsCaughtAsGenericError(): void
    {
        $throwing = new class implements ToolInterface {
            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('boom_tool', 'throws', ['type' => 'object', 'properties' => []]);
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function execute(array $arguments): string
            {
                throw new RuntimeException('boom https://x?key=secret', 1782700101);
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };

        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'boom_tool', [])]),
            $this->response('recovered'),
        ];
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback($this->queueCallback($queue));

        $service = $this->service($mgr, new ToolRegistry([$throwing]));
        $result  = $service->runLoop([$this->userTurn('blow up')], new LlmConfiguration(), null);

        self::assertCount(1, $result->trace);
        self::assertTrue($result->trace[0]->isError);
        // Generic message only: no exception text (URL / credentials / DBAL).
        self::assertSame('Error: tool "boom_tool" failed.', $result->trace[0]->result);
        self::assertStringNotContainsString('secret', $result->trace[0]->result);
        self::assertStringNotContainsString('https', $result->trace[0]->result);
        self::assertSame('recovered', $result->finalContent);
        self::assertFalse($result->truncated);
    }

    #[Test]
    public function toolFailureIsLoggedServerSideWithFullDetail(): void
    {
        $throwing = new class implements ToolInterface {
            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('boom_tool', 'throws', ['type' => 'object', 'properties' => []]);
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function execute(array $arguments): string
            {
                throw new RuntimeException('boom https://x?key=secret', 1782700101);
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };

        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'boom_tool', [])]),
            $this->response('recovered'),
        ];
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback($this->queueCallback($queue));

        // The provider-facing result is generic (no egress of internals), but the
        // server-side logger MUST still receive the real exception detail.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('boom_tool'),
                self::callback(
                    static fn(mixed $context): bool => is_array($context)
                        && ($context['exception'] ?? null) instanceof Throwable,
                ),
            );

        $service = $this->service($mgr, new ToolRegistry([$throwing]), $logger);
        $service->runLoop([$this->userTurn('blow up')], new LlmConfiguration(), null);
    }

    #[Test]
    public function assistantTurnShapeIsOpenAiCompatible(): void
    {
        $captured = [];
        $queue    = [
            $this->response('', [new ToolCall('call_1', 'fetch_logs', [])]),
            $this->response('done'),
        ];

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback($this->queueCallback($queue, $captured));

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs', 'LOGS')]));
        $service->runLoop([$this->userTurn('show logs')], new LlmConfiguration(), null);

        // The second round must carry the appended assistant + tool turns as
        // typed ChatMessage VOs whose wire form stays OpenAI-compatible.
        $round2    = self::arr($captured[1] ?? null);
        $assistant = $round2[1] ?? null;
        self::assertInstanceOf(ChatMessage::class, $assistant);
        self::assertSame('assistant', $assistant->role);

        $wire     = $assistant->toArray();
        $calls    = self::arr($wire['tool_calls'] ?? null);
        $function = self::arr(self::arr($calls[0] ?? null)['function'] ?? null);
        self::assertSame('call_1', self::arr($calls[0] ?? null)['id'] ?? null);
        self::assertSame('function', self::arr($calls[0] ?? null)['type'] ?? null);
        // Empty arguments MUST serialise to an object, not an array.
        self::assertSame('{}', $function['arguments'] ?? null);

        $toolTurn = $round2[2] ?? null;
        self::assertInstanceOf(ChatMessage::class, $toolTurn);
        self::assertSame('tool', $toolTurn->role);
        self::assertSame('call_1', $toolTurn->toolCallId);
        self::assertSame('LOGS', $toolTurn->content);
        self::assertSame('call_1', $toolTurn->toArray()['tool_call_id'] ?? null);
    }

    #[Test]
    public function capHitSynthesisesFinalAnswerAndMarksTruncated(): void
    {
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        // The model never stops requesting tools; each tools round reports usage.
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [new ToolCall('call_x', 'loop_tool', [])], 10, 5));
        // Exactly one no-tools synthesis completion closes the loop, with its
        // own distinct usage split.
        $mgr->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->response('SYNTHESISED', null, 3, 2));

        $runTrace = new RunTrace();
        $service  = $this->service($mgr, new ToolRegistry([new FakeTool('loop_tool')]));
        $result   = $service->runLoop([$this->userTurn('loop')], new LlmConfiguration(), null, null, 2, $runTrace);

        self::assertSame(2, $result->iterations);
        self::assertTrue($result->truncated);
        self::assertSame('SYNTHESISED', $result->finalContent);
        self::assertCount(2, $result->trace);

        // Usage is summed across both tools rounds (2 × 10/5) AND the synthesis
        // completion (3/2): 23 prompt, 12 completion. Pins the two += accumulators
        // on the cap-hit path (a `-=` or `=` mutant would yield 17/8 or 3/2).
        self::assertSame(23, $result->usage->promptTokens);
        self::assertSame(12, $result->usage->completionTokens);
        self::assertSame(35, $result->usage->totalTokens);

        // The synthesis is recorded as its OWN round after the last tool round:
        // iterations (2) + 1 = round 3. It is the sole request step offering no
        // tools, and the sole LLM step whose content is the synthesised answer.
        $synthesisRequests = array_values(array_filter(
            $runTrace->getSteps(),
            static fn(RunStep $s): bool => $s->kind === RunStep::KIND_REQUEST && $s->toolSpecs === [],
        ));
        self::assertCount(1, $synthesisRequests);
        self::assertSame(3, $synthesisRequests[0]->round);

        $synthesisLlm = array_values(array_filter(
            $runTrace->getSteps(),
            static fn(RunStep $s): bool => $s->kind === RunStep::KIND_LLM && $s->content === 'SYNTHESISED',
        ));
        self::assertCount(1, $synthesisLlm);
        self::assertSame(3, $synthesisLlm[0]->round);
    }

    #[Test]
    public function allowListIsForwardedToRegistry(): void
    {
        $capturedTools = [];
        $response      = $this->response('done');

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            // $messages is an unused positional placeholder for the $tools arg.
            ->willReturnCallback(function (array $messages, array $tools) use (&$capturedTools, $response): CompletionResponse {
                // $messages must stay first so $tools binds positionally to the
                // real chatWithToolsForConfiguration signature; assert it rather
                // than leave it unused.
                self::assertNotSame([], $messages);
                $capturedTools[] = $tools;

                return $response;
            });

        $registry = new ToolRegistry([new FakeTool('fetch_logs'), new FakeTool('read_meta')]);
        $service  = $this->service($mgr, $registry);
        $service->runLoop([$this->userTurn('hi')], new LlmConfiguration(), ['fetch_logs']);

        $specs = self::arr($capturedTools[0] ?? null);
        $names = array_map(
            static fn(mixed $s): string => $s instanceof ToolSpec ? $s->name : '<not-a-spec>',
            array_values($specs),
        );
        self::assertSame(['fetch_logs'], $names);
    }

    #[Test]
    public function budgetExceededMidLoopReturnsPartialTruncated(): void
    {
        $budgetException = new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_MONTHLY_COST, 10.0, 5.0),
        );

        $calls = 0;
        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback(function () use (&$calls, $budgetException): CompletionResponse {
                ++$calls;
                if ($calls === 1) {
                    return $this->response('', [new ToolCall('call_1', 'fetch_logs', [])]);
                }

                throw $budgetException;
            });

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs', 'LOGS')]));
        $result  = $service->runLoop([$this->userTurn('show logs')], new LlmConfiguration(), null);

        self::assertTrue($result->truncated);
        self::assertSame('', $result->finalContent);
        self::assertCount(1, $result->trace);
        self::assertSame('fetch_logs', $result->trace[0]->name);
        self::assertSame('LOGS', $result->trace[0]->result);
    }

    #[Test]
    public function budgetExceededIsLoggedServerSide(): void
    {
        // A budget stop and an iteration-cap stop both surface as
        // truncated=true with an empty answer; the denial must be logged so
        // operators can tell them apart (and see which bucket tripped).
        $budgetException = new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_MONTHLY_COST, 10.0, 5.0),
        );

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willThrowException($budgetException);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('budget'),
                self::callback(static fn(array $ctx): bool => ($ctx['exception'] ?? null) instanceof BudgetExceededException),
            );

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs', 'LOGS')]), $logger);
        $result  = $service->runLoop([$this->userTurn('show logs')], new LlmConfiguration(), null);

        self::assertTrue($result->truncated);
    }

    #[Test]
    public function oversizedToolResultIsCappedToMaxBytes(): void
    {
        // A tool returning more than the byte cap must be truncated before it is
        // fed back to the model, with a visible marker. A distinctive leading
        // token pins the cut window's start offset.
        $big = 'START' . str_repeat('x', 60000);

        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'big_tool', [])]),
            $this->response('done'),
        ];
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback($this->queueCallback($queue));

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('big_tool', $big)]));
        $result  = $service->runLoop([$this->userTurn('go')], new LlmConfiguration(), null);

        self::assertCount(1, $result->trace);
        $toolResult = $result->trace[0]->result;
        // Reserving the marker's bytes makes the capped output land on the cap
        // exactly (content budget + marker = 50000).
        self::assertSame(50000, strlen($toolResult));
        self::assertStringContainsString('tool result truncated', $toolResult);
        // The cut keeps the head of the tool output (offset 0, not 1 or -1): the
        // result still begins with the distinctive leading token.
        self::assertStringStartsWith('START', $toolResult);
        // The exact truncation marker (order + both operands of the concat) is
        // appended verbatim, embedding the byte cap and the trailing " bytes]".
        self::assertStringEndsWith("\n…[tool result truncated at 50000 bytes]", $toolResult);
    }

    #[Test]
    public function resultAtExactlyMaxBytesIsReturnedUntruncated(): void
    {
        // Boundary: a tool result whose length equals the cap exactly must pass
        // through unchanged (the guard is `<=`, not `<`) — no marker appended.
        $exact = str_repeat('x', 50000);

        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'edge_tool', [])]),
            $this->response('done'),
        ];
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback($this->queueCallback($queue));

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('edge_tool', $exact)]));
        $result  = $service->runLoop([$this->userTurn('go')], new LlmConfiguration(), null);

        self::assertCount(1, $result->trace);
        $toolResult = $result->trace[0]->result;
        self::assertSame(50000, strlen($toolResult));
        self::assertStringNotContainsString('truncated', $toolResult);
        self::assertSame($exact, $toolResult);
    }

    #[Test]
    public function usageTokensSummedAcrossIterations(): void
    {
        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'fetch_logs', [])], 10, 5),
            $this->response('done', null, 3, 2),
        ];
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback($this->queueCallback($queue));

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs', 'LOGS')]));
        $result  = $service->runLoop([$this->userTurn('show logs')], new LlmConfiguration(), null);

        self::assertSame(13, $result->usage->promptTokens);
        self::assertSame(7, $result->usage->completionTokens);
        self::assertSame(20, $result->usage->totalTokens);
    }

    #[Test]
    public function emptyAllowListDoesSinglePlainCompletion(): void
    {
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        // No tools offered ⇒ exactly one plain completion, never the tools path.
        $mgr->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->response('plain answer'));
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');

        // A tool IS registered, but the empty allow-list offers none of them.
        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs')]));
        $result  = $service->runLoop([$this->userTurn('hi')], new LlmConfiguration(), []);

        self::assertSame('plain answer', $result->finalContent);
        self::assertSame([], $result->trace);
        self::assertSame(1, $result->iterations);
        self::assertFalse($result->truncated);
    }

    #[Test]
    public function disallowedButRegisteredToolIsRejectedAndNotExecuted(): void
    {
        $spy = new class implements ToolInterface {
            public bool $executed = false;

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('read_meta', 'reads meta', ['type' => 'object', 'properties' => []]);
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function execute(array $arguments): string
            {
                $this->executed = true;

                return 'META';
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };

        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            // The model calls a registered tool that is NOT in the allow-list.
            $this->response('', [new ToolCall('call_1', 'read_meta', [])]),
            $this->response('done'),
        ];
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback($this->queueCallback($queue));

        $registry = new ToolRegistry([new FakeTool('fetch_logs'), $spy]);
        $service  = $this->service($mgr, $registry);
        $result   = $service->runLoop([$this->userTurn('go')], new LlmConfiguration(), ['fetch_logs']);

        self::assertCount(1, $result->trace);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringContainsString('not permitted', $result->trace[0]->result);
        self::assertFalse($spy->executed, 'a disallowed tool must never be executed');
        self::assertSame('done', $result->finalContent);
    }

    #[Test]
    public function globallyDisabledToolIsNeverOfferedEvenWhenExplicitlyAllowed(): void
    {
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        // The disabled tool drops out of the effective set ⇒ no tools offered ⇒
        // exactly one plain completion, never the tools path.
        $mgr->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->response('plain answer'));
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');

        // fetch_logs IS registered, but the global gate reports it disabled.
        $service = new ToolLoopService(
            $mgr,
            new ToolRegistry([new FakeTool('fetch_logs')]),
            new FakeToolAvailability([]),
        );
        // The caller explicitly lists the disabled tool — the gate still wins.
        $result = $service->runLoop([$this->userTurn('hi')], new LlmConfiguration(), ['fetch_logs']);

        self::assertSame('plain answer', $result->finalContent);
        self::assertSame([], $result->trace);
        self::assertSame(1, $result->iterations);
    }

    #[Test]
    public function nullAllowListDefaultsToEnabledSetNotEveryRegisteredTool(): void
    {
        $capturedTools = [];
        $response      = $this->response('done');

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback(function (array $messages, array $tools) use (&$capturedTools, $response): CompletionResponse {
                // $messages must stay first so $tools binds positionally to the
                // real chatWithToolsForConfiguration signature; assert it rather
                // than leave it unused.
                self::assertNotSame([], $messages);
                $capturedTools[] = $tools;

                return $response;
            });

        // Two tools registered, but only one is globally enabled.
        $registry = new ToolRegistry([new FakeTool('fetch_logs'), new FakeTool('read_meta')]);
        $service  = new ToolLoopService($mgr, $registry, new FakeToolAvailability(['fetch_logs']));
        // null ⇒ "no per-run restriction" ⇒ collapses to the enabled set only.
        $service->runLoop([$this->userTurn('hi')], new LlmConfiguration(), null);

        $specs = self::arr($capturedTools[0] ?? null);
        $names = array_map(
            static fn(mixed $s): string => $s instanceof ToolSpec ? $s->name : '<not-a-spec>',
            array_values($specs),
        );
        self::assertSame(['fetch_logs'], $names);
    }

    /**
     * Build a ToolLoopService whose global availability gate is a no-op: every
     * registered tool counts as enabled, so the loop's effective allow-set is
     * governed solely by the caller's $allowedToolNames (the behaviour these
     * tests target). Gating-specific tests build the service inline with a
     * restricted {@see FakeToolAvailability}.
     */
    #[Test]
    public function suspendsWhenTheModelCallsAnApprovalRequiredTool(): void
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [new ToolCall('call_1', 'delete_thing', ['id' => 7])], 5, 2));
        $service = $this->service($mgr, new ToolRegistry([$this->approvalTool()]));

        $state = $this->suspend($service);

        self::assertCount(1, $state->pendingCalls);
        self::assertSame('delete_thing', $state->toolCalls()[0]->name);
        self::assertSame(['id' => 7], $state->toolCalls()[0]->arguments);
        // The transcript holds the user turn + the assistant tool-call turn,
        // captured before any tool executed.
        self::assertCount(2, $state->messages);
        self::assertSame(1, $state->iterations);
        self::assertSame(5, $state->promptTokens);
    }

    #[Test]
    public function resumeApprovedExecutesThePendingCallThenContinues(): void
    {
        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'delete_thing', [])], 5, 2),
            $this->response('deleted and done', null, 3, 4),
        ];
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback($this->queueCallback($queue));
        $service = $this->service($mgr, new ToolRegistry([$this->approvalTool()]));

        $state = $this->suspend($service);

        $trace  = new RunTrace();
        $result = $service->resume($state, true, new LlmConfiguration(), null, $trace);

        self::assertSame('deleted and done', $result->finalContent);
        // The approved tool really executed (recorded on the trace) with its result.
        $toolSteps = array_values(array_filter($trace->getSteps(), static fn(RunStep $s): bool => $s->kind === RunStep::KIND_TOOL));
        self::assertCount(1, $toolSteps);
        self::assertSame('delete_thing', $toolSteps[0]->toolName);
        self::assertSame('DELETED', $toolSteps[0]->toolResult);
        self::assertFalse($toolSteps[0]->toolIsError);
        // Totals span the whole run: pre-suspend (5+2) + post-resume (3+4).
        self::assertSame(14, $result->usage->totalTokens);
    }

    #[Test]
    public function resumeDeniedInjectsARefusalThenContinues(): void
    {
        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $queue = [
            $this->response('', [new ToolCall('call_1', 'delete_thing', [])]),
            $this->response('ok, cancelled'),
        ];
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback($this->queueCallback($queue));
        $service = $this->service($mgr, new ToolRegistry([$this->approvalTool()]));

        $state = $this->suspend($service);

        $trace  = new RunTrace();
        $result = $service->resume($state, false, new LlmConfiguration(), null, $trace);

        self::assertSame('ok, cancelled', $result->finalContent);
        // The pending tool was NOT executed; a denial result was fed back instead.
        $toolSteps = array_values(array_filter($trace->getSteps(), static fn(RunStep $s): bool => $s->kind === RunStep::KIND_TOOL));
        self::assertCount(1, $toolSteps);
        self::assertTrue($toolSteps[0]->toolIsError);
        self::assertStringContainsString('denied', $toolSteps[0]->toolResult ?? '');
    }

    #[Test]
    public function suspendCapturesTheAllowListAndOptionsForRestoreOnResume(): void
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [new ToolCall('call_1', 'delete_thing', [])]));
        $service = $this->service($mgr, new ToolRegistry([$this->approvalTool()]));

        $options = new ToolOptions(temperature: 0.3, maxTokens: 512);

        $state = null;
        try {
            $service->runLoop([$this->userTurn('go')], new LlmConfiguration(), ['delete_thing'], $options);
        } catch (ToolApprovalRequiredException $e) {
            $state = $e->state;
        }

        self::assertNotNull($state);
        self::assertSame(['delete_thing'], $state->allowedToolNames);
        self::assertSame(0.3, $state->options['temperature'] ?? null);
        self::assertSame(512, $state->options['max_tokens'] ?? null);
    }

    #[Test]
    public function resumeReAppliesTheGateAndRefusesANoLongerOfferedTool(): void
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        // The tool was disabled while the run was suspended: the registry no longer
        // offers it, so resolveOfferedNames() returns nothing and the continuation
        // does a single plain completion.
        $mgr->method('chatWithConfiguration')->willReturn($this->response('closed out'));
        $service = $this->service($mgr, new ToolRegistry([]));

        $assistantTurn = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{}']]]];
        $state         = new SuspendedRunState(
            [['role' => 'user', 'content' => 'delete it'], $assistantTurn],
            [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{}']]],
            1,
            5,
            2,
            ['delete_thing'],
            [],
        );

        $trace  = new RunTrace();
        $result = $service->resume($state, true, new LlmConfiguration(), null, $trace);

        // Approved, but the tool is no longer offered → fail-closed, NOT executed.
        $toolSteps = array_values(array_filter($trace->getSteps(), static fn(RunStep $s): bool => $s->kind === RunStep::KIND_TOOL));
        self::assertCount(1, $toolSteps);
        self::assertTrue($toolSteps[0]->toolIsError);
        self::assertStringContainsString('no longer permitted', $toolSteps[0]->toolResult ?? '');

        // The no-offered-tools synthesis branch still folds the pre-suspend
        // counters (1 iteration + 5/2 tokens) into the totals, not just its own
        // single round — the whole run is not under-reported.
        self::assertSame(2, $result->iterations);
        self::assertGreaterThanOrEqual(5, $result->usage->promptTokens);
        self::assertGreaterThanOrEqual(2, $result->usage->completionTokens);
    }

    /**
     * Drive a run to its approval suspension and return the captured state.
     */
    #[Test]
    public function doesNotSuspendForARegisteredApprovalToolThatIsNotOffered(): void
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback($this->queueCallback([
            $this->response('', [new ToolCall('call_1', 'delete_thing', [])]),
            $this->response('handled'),
        ]));
        $service = $this->service($mgr, new ToolRegistry([$this->approvalTool(), new FakeTool('safe_tool')]));

        // Only safe_tool is offered; delete_thing (an approval tool) is registered
        // but NOT in the allow-list. A model naming it must be refused by the gate,
        // not raise a spurious pending-approval suspension.
        $result = $service->runLoop([$this->userTurn('go')], new LlmConfiguration(), ['safe_tool']);

        self::assertSame('handled', $result->finalContent);
        self::assertCount(1, $result->trace);
        self::assertTrue($result->trace[0]->isError);
        self::assertNotSame('DELETED', $result->trace[0]->result);
    }

    #[Test]
    public function accumulatesCountersAcrossASecondSuspend(): void
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        // The resumed continuation immediately calls the approval tool again.
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback($this->queueCallback([
            $this->response('', [new ToolCall('call_2', 'delete_thing', [])]),
        ]));
        $service = $this->service($mgr, new ToolRegistry([$this->approvalTool()]));

        // A state that already accumulated 3 iterations before the first suspend.
        $assistantTurn = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{}']]]];
        $state         = new SuspendedRunState(
            [$this->userTurn('go'), $assistantTurn],
            [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{}']]],
            3,
            30,
            12,
            ['delete_thing'],
            [],
        );

        $second = null;
        try {
            $service->resume($state, true, new LlmConfiguration(), null, null, 7);
        } catch (ToolApprovalRequiredException $e) {
            $second = $e->state;
        }

        self::assertNotNull($second);
        // The re-suspend carries the ACCUMULATED counters (3 prior iterations +
        // the continuation's one round; the pre-suspend tokens preserved), not
        // just the continuation's own segment.
        self::assertSame(4, $second->iterations);
        self::assertGreaterThanOrEqual(30, $second->promptTokens);
        self::assertGreaterThanOrEqual(12, $second->completionTokens);
    }

    #[Test]
    public function resumeInjectsTheActingUserUidForBudgetChecks(): void
    {
        $capturedOptions = null;
        $mgr             = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (array $messages, array $tools, LlmConfiguration $config, ?ToolOptions $options = null) use (&$capturedOptions): CompletionResponse {
                $capturedOptions = $options;

                return $this->response('done');
            },
        );
        $service = $this->service($mgr, new ToolRegistry([new FakeTool('safe_tool')]));
        $state   = new SuspendedRunState([$this->userTurn('go')], [], 1, 5, 2, ['safe_tool'], []);

        $service->resume($state, true, new LlmConfiguration(), null, null, 42);

        // The resumed continuation carries the acting user's uid so BudgetMiddleware
        // gates it (the uid is not part of the persisted options).
        self::assertInstanceOf(ToolOptions::class, $capturedOptions);
        self::assertSame(42, $capturedOptions->getBeUserUid());
    }

    private function suspend(ToolLoopService $service): SuspendedRunState
    {
        try {
            $service->runLoop([$this->userTurn('delete it')], new LlmConfiguration(), null);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }
        self::fail('Expected the run to suspend for approval.');
    }

    /**
     * A tool that opts into human approval (marker), returning 'DELETED' when run.
     */
    private function approvalTool(): ToolInterface
    {
        return new class implements ToolInterface, RequiresApprovalInterface {
            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('delete_thing', 'deletes a thing', ['type' => 'object', 'properties' => []]);
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function execute(array $arguments): string
            {
                return 'DELETED';
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };
    }

    private function service(
        LlmServiceManagerInterface $mgr,
        ToolRegistry $registry,
        ?LoggerInterface $logger = null,
    ): ToolLoopService {
        return new ToolLoopService(
            $mgr,
            $registry,
            new FakeToolAvailability($registry->names()),
            $logger,
        );
    }

    /**
     * Build a CompletionResponse fixture with optional tool calls and usage.
     *
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(
        string $content,
        ?array $toolCalls = null,
        int $promptTokens = 0,
        int $completionTokens = 0,
    ): CompletionResponse {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: UsageStatistics::fromTokens($promptTokens, $completionTokens),
            toolCalls: $toolCalls,
        );
    }

    /**
     * @return array<string, string>
     */
    private function userTurn(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Script a queue of CompletionResponses for chatWithToolsForConfiguration,
     * recording each call's $messages argument into $captured.
     *
     * @param list<CompletionResponse>     $queue
     * @param list<array<int, mixed>>|null $captured
     *
     * @return callable(array<int, mixed>): CompletionResponse
     */
    private function queueCallback(array $queue, ?array &$captured = null): callable
    {
        return function (array $messages) use (&$queue, &$captured): CompletionResponse {
            if ($captured !== null) {
                $captured[] = $messages;
            }

            $next = array_shift($queue);
            if (!$next instanceof CompletionResponse) {
                throw new RuntimeException('Scripted response queue underflow.', 1782700100);
            }

            return $next;
        };
    }

    /**
     * Assert a mixed value is an array and return it narrowed (PHPStan-clean
     * deep access into captured raw-array message turns).
     *
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    #[Test]
    public function adminOnlyToolIsNeverOfferedToNonAdmin(): void
    {
        $beUserBackup    = $GLOBALS['BE_USER'] ?? null;
        $nonAdmin        = new BackendUserAuthentication();
        $nonAdmin->user  = ['uid' => 2, 'admin' => 0];
        $GLOBALS['BE_USER'] = $nonAdmin;

        try {
            $mgr = $this->createMock(LlmServiceManagerInterface::class);
            // The admin-only tool is filtered out for a non-admin ⇒ no tools
            // offered ⇒ exactly one plain completion, never the tools path.
            $mgr->expects(self::once())
                ->method('chatWithConfiguration')
                ->willReturn($this->response('plain answer'));
            $mgr->expects(self::never())->method('chatWithToolsForConfiguration');

            // Tool is registered, globally enabled, AND explicitly allowed — but
            // it requiresAdmin and the acting user is not an admin.
            $service = new ToolLoopService(
                $mgr,
                new ToolRegistry([new FakeTool('fetch_logs', 'ok', true, true)]),
                new FakeToolAvailability(['fetch_logs']),
            );
            $result = $service->runLoop([$this->userTurn('hi')], new LlmConfiguration(), ['fetch_logs']);

            self::assertSame('plain answer', $result->finalContent);
            self::assertSame([], $result->trace);
        } finally {
            $GLOBALS['BE_USER'] = $beUserBackup;
        }
    }

    #[Test]
    public function adminIsOfferedAdminOnlyToolAndItExecutesThroughTheLoop(): void
    {
        // Positive RBAC case (mirror of adminOnlyToolIsNeverOfferedToNonAdmin):
        // when the acting user IS an admin, the admin-only tool survives the
        // runtime filter, is offered on the tools path, executes, and its real
        // result is recorded in the trace.
        $beUserBackup       = $GLOBALS['BE_USER'] ?? null;
        $admin              = new BackendUserAuthentication();
        $admin->user        = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $admin;

        try {
            $mgr   = self::createStub(LlmServiceManagerInterface::class);
            $queue = [
                // The model calls the admin-only tool, then answers.
                $this->response('', [new ToolCall('call_1', 'fetch_logs', [])]),
                $this->response('logs reviewed'),
            ];
            $mgr->method('chatWithToolsForConfiguration')
                ->willReturnCallback($this->queueCallback($queue));

            // requiresAdmin = true; globally enabled; explicitly allowed.
            $service = new ToolLoopService(
                $mgr,
                new ToolRegistry([new FakeTool('fetch_logs', 'LOGS', true, true)]),
                new FakeToolAvailability(['fetch_logs']),
            );
            $result = $service->runLoop([$this->userTurn('show logs')], new LlmConfiguration(), ['fetch_logs']);

            self::assertCount(1, $result->trace);
            self::assertSame('fetch_logs', $result->trace[0]->name);
            self::assertSame('LOGS', $result->trace[0]->result, 'the admin-only tool actually executed');
            self::assertFalse($result->trace[0]->isError);
            self::assertSame('logs reviewed', $result->finalContent);
            self::assertFalse($result->truncated);
        } finally {
            $GLOBALS['BE_USER'] = $beUserBackup;
        }
    }

    #[Test]
    public function disabledGateComposesWithAdminGateForAdminUser(): void
    {
        // The two fail-closed gates compose: an admin-only tool that the global
        // availability gate reports as DISABLED is dropped by the disabled gate
        // BEFORE the admin gate ever sees it, so even an admin is not offered it
        // ⇒ no tools ⇒ exactly one plain completion.
        $beUserBackup       = $GLOBALS['BE_USER'] ?? null;
        $admin              = new BackendUserAuthentication();
        $admin->user        = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $admin;

        try {
            $mgr = $this->createMock(LlmServiceManagerInterface::class);
            $mgr->expects(self::once())
                ->method('chatWithConfiguration')
                ->willReturn($this->response('plain answer'));
            $mgr->expects(self::never())->method('chatWithToolsForConfiguration');

            // Tool requiresAdmin AND is registered, but the global gate reports
            // NO enabled tools — the caller even explicitly allows it.
            $service = new ToolLoopService(
                $mgr,
                new ToolRegistry([new FakeTool('fetch_logs', 'LOGS', true, true)]),
                new FakeToolAvailability([]),
            );
            $result = $service->runLoop([$this->userTurn('hi')], new LlmConfiguration(), ['fetch_logs']);

            self::assertSame('plain answer', $result->finalContent);
            self::assertSame([], $result->trace);
            self::assertSame(1, $result->iterations);
        } finally {
            $GLOBALS['BE_USER'] = $beUserBackup;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetMetadata(?ToolOptions $options): array
    {
        $service = new ToolLoopService(
            self::createStub(LlmServiceManagerInterface::class),
            new ToolRegistry([]),
            new FakeToolAvailability([]),
        );
        /** @var array<string, mixed> $result */
        $result = (new ReflectionClass($service))->getMethod('budgetMetadata')->invoke($service, $options);

        return $result;
    }

    #[Test]
    public function budgetMetadataForwardsBeUserUidAndPlannedCost(): void
    {
        // The billing contract: ToolOptions budget fields must reach the
        // BudgetMiddleware metadata that chatWithConfiguration() receives.
        $meta = $this->budgetMetadata(new ToolOptions(beUserUid: 42, plannedCost: 0.05));

        self::assertSame(42, $meta[BudgetMiddleware::METADATA_BE_USER_UID] ?? null);
        self::assertSame(0.05, $meta[BudgetMiddleware::METADATA_PLANNED_COST] ?? null);
    }

    #[Test]
    public function budgetMetadataIsEmptyForNullOptions(): void
    {
        self::assertSame([], $this->budgetMetadata(null));
    }

    #[Test]
    public function budgetMetadataOmitsUnsetFields(): void
    {
        // Only beUserUid set → the plannedCost key must be absent, not null/0.
        $meta = $this->budgetMetadata(new ToolOptions(beUserUid: 7));

        self::assertSame(7, $meta[BudgetMiddleware::METADATA_BE_USER_UID] ?? null);
        self::assertArrayNotHasKey(BudgetMiddleware::METADATA_PLANNED_COST, $meta);
    }

    #[Test]
    public function defaultMaxIterationsIsFiveWhenNoCapGiven(): void
    {
        // No $maxIterations argument ⇒ the constructor default (5) governs the
        // cap. The model never stops requesting tools, so the loop runs the full
        // five rounds before synthesising — pinning the default to 5 (a 4 or 6
        // default would end at 4 or 6 iterations / trace entries).
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [new ToolCall('call_x', 'loop_tool', [])]));
        $mgr->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->response('SYNTHESISED'));

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('loop_tool')]));
        $result  = $service->runLoop([$this->userTurn('loop')], new LlmConfiguration(), null);

        self::assertSame(5, $result->iterations);
        self::assertTrue($result->truncated);
        self::assertCount(5, $result->trace);
        self::assertSame('SYNTHESISED', $result->finalContent);
    }

    #[Test]
    public function dryRunReturnsEmptyResultAndCallsNoProvider(): void
    {
        // A dry run assembles the prompt and returns immediately: no provider
        // call, an empty answer, zero iterations, not truncated, zero usage.
        // Passing NO RunTrace exercises the null-safe recordAssembledMessages
        // (a non-null-safe mutant would fatal on the null trace).
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');
        $mgr->expects(self::never())->method('chatWithConfiguration');

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('noop')]));
        $result  = $service->runLoop(
            [$this->userTurn('hi')],
            new LlmConfiguration(),
            null,
            null,
            null,
            null,
            new RunAugmentation([], [], true),
        );

        self::assertSame('', $result->finalContent);
        self::assertSame([], $result->trace);
        self::assertSame(0, $result->iterations);
        self::assertFalse($result->truncated);
        self::assertSame(0, $result->usage->promptTokens);
        self::assertSame(0, $result->usage->completionTokens);
        self::assertSame(0, $result->usage->totalTokens);
    }

    #[Test]
    public function dryRunBakesSystemPromptOverrideAsLeadingSystemMessage(): void
    {
        // With an augmentation present and a per-run system-prompt override, the
        // override (non-empty) wins over the configuration prompt and is baked as
        // the FIRST assembled message. Recorded on the dry-run trace so it can be
        // asserted without a provider call.
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');
        $mgr->expects(self::never())->method('chatWithConfiguration');

        $runTrace = new RunTrace();
        $service  = $this->service($mgr, new ToolRegistry([new FakeTool('noop')]));
        $service->runLoop(
            [$this->userTurn('hi')],
            new LlmConfiguration(),
            null,
            new ToolOptions(systemPrompt: 'OVERRIDE_SYS'),
            null,
            $runTrace,
            new RunAugmentation([], [], true),
        );

        $steps = $runTrace->getSteps();
        self::assertNotSame([], $steps);
        $assembled = $steps[0];
        self::assertSame(RunStep::KIND_ASSEMBLED, $assembled->kind);

        $messages = $assembled->messagesSent;
        self::assertIsArray($messages);
        $first = self::arr($messages[0] ?? null);
        self::assertSame('system', $first['role'] ?? null);
        self::assertSame('OVERRIDE_SYS', $first['content'] ?? null);
    }

    #[Test]
    public function plainCompletionPathRecordsRequestAndLlmAtRoundOne(): void
    {
        // Empty allow-list ⇒ no tools offered ⇒ the single plain-completion
        // branch. It records exactly one request step and one LLM step, both at
        // round 1, with an empty tool-spec list — and a small, non-negative
        // elapsed duration (pinning elapsedMs's subtraction and /1e6 scaling).
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->response('plain answer'));
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');

        $runTrace = new RunTrace();
        $service  = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs')]));
        $service->runLoop([$this->userTurn('hi')], new LlmConfiguration(), [], null, null, $runTrace);

        $steps = $runTrace->getSteps();
        self::assertCount(2, $steps);
        self::assertSame(RunStep::KIND_REQUEST, $steps[0]->kind);
        self::assertSame(1, $steps[0]->round);
        self::assertSame([], $steps[0]->toolSpecs);
        self::assertSame(RunStep::KIND_LLM, $steps[1]->kind);
        self::assertSame(1, $steps[1]->round);
        self::assertGreaterThanOrEqual(0.0, $steps[1]->durationMs);
        self::assertLessThan(60000.0, $steps[1]->durationMs);
    }
}
