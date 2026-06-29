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
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

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

        // The second round must carry the appended assistant + tool turns.
        $round2    = self::arr($captured[1] ?? null);
        $assistant = self::arr($round2[1] ?? null);
        self::assertSame('assistant', $assistant['role'] ?? null);

        $calls    = self::arr($assistant['tool_calls'] ?? null);
        $function = self::arr(self::arr($calls[0] ?? null)['function'] ?? null);
        self::assertSame('call_1', self::arr($calls[0] ?? null)['id'] ?? null);
        self::assertSame('function', self::arr($calls[0] ?? null)['type'] ?? null);
        // Empty arguments MUST serialise to an object, not an array.
        self::assertSame('{}', $function['arguments'] ?? null);

        $toolTurn = self::arr($round2[2] ?? null);
        self::assertSame('tool', $toolTurn['role'] ?? null);
        self::assertSame('call_1', $toolTurn['tool_call_id'] ?? null);
        self::assertSame('LOGS', $toolTurn['content'] ?? null);
    }

    #[Test]
    public function capHitSynthesisesFinalAnswerAndMarksTruncated(): void
    {
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        // The model never stops requesting tools.
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [new ToolCall('call_x', 'loop_tool', [])]));
        // Exactly one no-tools synthesis completion closes the loop.
        $mgr->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->response('SYNTHESISED'));

        $service = $this->service($mgr, new ToolRegistry([new FakeTool('loop_tool')]));
        $result  = $service->runLoop([$this->userTurn('loop')], new LlmConfiguration(), null, null, 2);

        self::assertSame(2, $result->iterations);
        self::assertTrue($result->truncated);
        self::assertSame('SYNTHESISED', $result->finalContent);
        self::assertCount(2, $result->trace);
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
}
