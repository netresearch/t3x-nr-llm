<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\Builtin\FetchLogsTool;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * End-to-end agent loop over a REAL builtin tool.
 *
 * The unit {@see \Netresearch\NrLlm\Tests\Unit\Service\Tool\ToolLoopServiceTest}
 * drives the loop with an in-memory FakeTool and a hand-written availability
 * double. Here the loop runs the actual admin-curated {@see FetchLogsTool}
 * through the REAL {@see ToolRegistry} and the REAL DB-backed
 * {@see ToolAvailabilityService} (its `tx_nrllm_tool_state` overrides), with the
 * acting admin resolved from `$GLOBALS['BE_USER']`. Only the LLM manager is
 * stubbed (it scripts one tool call followed by a final answer — no network);
 * everything from the availability gate through the admin gate down into
 * `FetchLogsTool::execute()` reading real `sys_log` rows is production code, and
 * the trace is asserted to carry the tool's REAL formatted output.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceBuiltinTest extends AbstractFunctionalTestCase
{
    private ConnectionPool $connectionPool;

    /** @var BackendUserAuthentication|object|null */
    private mixed $beUserBackup = null;

    /**
     * The admin backend user the tool loop authorises against in setUp; the same
     * object is threaded into the run's {@see ToolExecutionContext} so the REAL
     * admin gate reads it exactly as it read the ambient `$GLOBALS['BE_USER']`.
     */
    private BackendUserAuthentication $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        // FetchLogsTool is admin-only; the runtime RBAC gate reads the acting
        // backend user from $GLOBALS['BE_USER'] and fails closed when absent.
        $this->beUserBackup = $GLOBALS['BE_USER'] ?? null;
        $admin              = new BackendUserAuthentication();
        $admin->user        = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $admin;
        $this->actingUser   = $admin;
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->beUserBackup;
        parent::tearDown();
    }

    #[Test]
    public function realFetchLogsToolExecutesThroughLoopAndTraceRecordsItsOutput(): void
    {
        $this->importFixture('sys_log_tools.csv');

        // Script the LLM: round 1 asks for the tool, round 2 (after the real tool
        // result is fed back) gives the final answer.
        $queue = [
            $this->response('', [new ToolCall('call_1', 'fetch_logs', ['limit' => 2])]),
            $this->response('Here is a summary of the recent logs.'),
        ];
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturnCallback(function () use (&$queue): CompletionResponse {
                $next = array_shift($queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1799990001);
                }

                return $next;
            });

        $service = $this->buildService($mgr);
        $result  = $service->runLoop([$this->userTurn('show me the logs')], new LlmConfiguration(), $this->contextFor($this->actingUser), null);

        // The real builtin tool ran exactly once and its REAL output is in the trace.
        self::assertCount(1, $result->trace);
        self::assertSame('fetch_logs', $result->trace[0]->name);
        self::assertFalse($result->trace[0]->isError, 'the real tool executed without error');

        $toolOutput = $result->trace[0]->result;
        // FetchLogsTool formats newest-first; the limit=2 it was called with
        // surfaces the two newest fixture rows (see FetchLogsToolTest).
        self::assertStringContainsString('Cache cleared', $toolOutput);
        self::assertStringContainsString('Login failed', $toolOutput);
        // PII redaction in the real tool still holds end-to-end.
        self::assertStringNotContainsString('203.0.113.55', $toolOutput);

        self::assertSame('Here is a summary of the recent logs.', $result->finalContent);
        self::assertFalse($result->truncated);
    }

    #[Test]
    public function adminOnlyBuiltinToolIsNotOfferedToNonAdminEndToEnd(): void
    {
        // Flip the acting user to a non-admin: the REAL admin gate must drop the
        // admin-only FetchLogsTool, leaving no tools ⇒ a single plain completion,
        // and the LLM's tools path is never taken.
        $nonAdmin           = new BackendUserAuthentication();
        $nonAdmin->user     = ['uid' => 2, 'admin' => 0];
        $GLOBALS['BE_USER'] = $nonAdmin;

        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->expects(self::once())
            ->method('chatWithConfiguration')
            ->willReturn($this->response('plain answer'));
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');

        $service = $this->buildService($mgr);
        // The caller even explicitly allows the tool — the admin gate still wins.
        $result = $service->runLoop([$this->userTurn('show me the logs')], new LlmConfiguration(), $this->contextFor($nonAdmin), ['fetch_logs']);

        self::assertSame('plain answer', $result->finalContent);
        self::assertSame([], $result->trace);
        self::assertSame(1, $result->iterations);
    }

    /**
     * Wire the loop with the real registry + real DB-backed availability service
     * over the single real {@see FetchLogsTool}.
     */
    private function buildService(LlmServiceManagerInterface $mgr): ToolLoopService
    {
        $registry     = new ToolRegistry([new FetchLogsTool($this->connectionPool)]);
        $availability = new ToolAvailabilityService($registry, new ToolStateRepository($this->connectionPool), new ToolGroupStateRepository($this->connectionPool));

        return new ToolLoopService($mgr, $registry, $availability);
    }

    /**
     * Build the run's execution context from the same live backend user the REAL
     * admin gate authorises against, so the tool loop scopes exactly as it did
     * when it read the ambient `$GLOBALS['BE_USER']`.
     */
    private function contextFor(BackendUserAuthentication $user): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($user);
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: UsageStatistics::fromTokens(0, 0),
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
}
