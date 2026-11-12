<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Observability\GenAiTelemetry;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolTelemetry;
use Netresearch\NrLlm\Tests\Unit\Observability\OpenTelemetryTestHarness;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The agent and tool part of the OpenTelemetry instrumentation (ADR-291): one
 * `invoke_agent` span per segment of a run, one `execute_tool` span per tool
 * execution, and a pause that is not a failure.
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(GenAiTelemetry::class)]
#[CoversClass(ToolTelemetry::class)]
final class ToolLoopServiceTracingTest extends TestCase
{
    use OpenTelemetryTestHarness;

    protected function tearDown(): void
    {
        $this->uninstallOpenTelemetrySdk();
        parent::tearDown();
    }

    #[Test]
    public function aRunIsAnAgentSpanWithItsToolExecutionsAsChildren(): void
    {
        $this->installOpenTelemetrySdk();
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnOnConsecutiveCalls(
            $this->response('', [new ToolCall('call_7', 'fetch_logs', ['query' => 'a secret argument'])], 10, 4),
            $this->response('all done', null, 20, 6),
        );
        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs', 'SECRET LOG LINES')]));

        $service->runLoop([['role' => 'user', 'content' => 'a private question']], $this->configuration('support'), ToolExecutionContext::none(), null);

        $agents = $this->spansNamed('invoke_agent support');
        $tools  = $this->spansNamed('execute_tool fetch_logs');
        self::assertCount(1, $agents);
        self::assertCount(1, $tools);

        $agent = $agents[0]->getAttributes()->toArray();
        self::assertSame(SpanKind::KIND_INTERNAL, $agents[0]->getKind());
        self::assertSame('invoke_agent', $agent['gen_ai.operation.name']);
        self::assertSame('support', $agent['gen_ai.agent.name']);
        self::assertSame(2, $agent['nr_llm.run.iterations']);
        self::assertSame('completed', $agent['nr_llm.run.outcome']);
        self::assertSame(30, $agent['gen_ai.usage.input_tokens']);
        self::assertSame(10, $agent['gen_ai.usage.output_tokens']);
        self::assertSame(StatusCode::STATUS_UNSET, $agents[0]->getStatus()->getCode());

        $tool = $tools[0]->getAttributes()->toArray();
        self::assertSame(SpanKind::KIND_INTERNAL, $tools[0]->getKind());
        self::assertSame($agents[0]->getSpanId(), $tools[0]->getParentSpanId());
        self::assertSame('execute_tool', $tool['gen_ai.operation.name']);
        self::assertSame('fetch_logs', $tool['gen_ai.tool.name']);
        self::assertSame('function', $tool['gen_ai.tool.type']);
        self::assertSame('call_7', $tool['gen_ai.tool.call.id']);
        self::assertSame(StatusCode::STATUS_UNSET, $tools[0]->getStatus()->getCode());

        $recorded = $this->everythingRecorded();
        foreach (['a secret argument', 'SECRET LOG LINES', 'a private question', 'all done'] as $content) {
            self::assertStringNotContainsString($content, $recorded);
        }
    }

    #[Test]
    public function aToolThatReturnsAFailureEndsItsSpanAsAnErrorWithoutItsText(): void
    {
        $this->installOpenTelemetrySdk();
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnOnConsecutiveCalls(
            $this->response('', [new ToolCall('call_1', 'flaky', [])]),
            $this->response('gave up'),
        );
        $service = $this->service($mgr, new ToolRegistry([$this->failingTool('flaky', false)]));

        $service->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration('support'), ToolExecutionContext::none(), null);

        $tool = $this->onlySpan('execute_tool flaky');
        self::assertSame(StatusCode::STATUS_ERROR, $tool->getStatus()->getCode());
        self::assertSame('tool_failed', $tool->getAttributes()->toArray()['error.type']);
        self::assertStringNotContainsString('database password', $this->everythingRecorded());
    }

    #[Test]
    public function aToolThatThrowsEndsItsSpanWithTheExceptionClassAndTheRunGoesOn(): void
    {
        $this->installOpenTelemetrySdk();
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnOnConsecutiveCalls(
            $this->response('', [new ToolCall('call_1', 'boom', [])]),
            $this->response('recovered'),
        );
        $service = $this->service($mgr, new ToolRegistry([$this->failingTool('boom', true)]));

        $result = $service->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration('support'), ToolExecutionContext::none(), null);

        self::assertSame('recovered', $result->finalContent);
        $tool = $this->onlySpan('execute_tool boom');
        self::assertSame(StatusCode::STATUS_ERROR, $tool->getStatus()->getCode());
        self::assertSame(RuntimeException::class, $tool->getAttributes()->toArray()['error.type']);
        self::assertSame([], $tool->getEvents());
        self::assertStringNotContainsString('database password', $this->everythingRecorded());
        self::assertSame(StatusCode::STATUS_UNSET, $this->onlySpan('invoke_agent support')->getStatus()->getCode());
    }

    #[Test]
    public function aRunThatPausesForInputIsNotAFailure(): void
    {
        $this->installOpenTelemetrySdk();
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')
            ->willReturn($this->response('', [new ToolCall('call_1', 'ask_user', [])]));
        $service = $this->service($mgr, new ToolRegistry([new FakeInputTool('ask_user')]));

        try {
            $service->runLoop([['role' => 'user', 'content' => 'weather?']], $this->configuration('support'), ToolExecutionContext::none(), ['ask_user']);
            self::fail('The run must pause for input.');
        } catch (ToolInputRequiredException) {
            // The pause is what the caller handles.
        }

        $agent = $this->onlySpan('invoke_agent support');
        self::assertSame(StatusCode::STATUS_UNSET, $agent->getStatus()->getCode());
        self::assertSame('suspended', $agent->getAttributes()->toArray()['nr_llm.run.outcome']);
        self::assertArrayNotHasKey('error.type', $agent->getAttributes()->toArray());
    }

    #[Test]
    public function aFailedRunEndsItsSpanWithTheExceptionClass(): void
    {
        $this->installOpenTelemetrySdk();
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willThrowException(new RuntimeException('prompt text leaked into a message'));
        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs')]));

        try {
            $service->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration('support'), ToolExecutionContext::none(), null);
            self::fail('The failure must reach the caller.');
        } catch (RuntimeException) {
            // Re-thrown untouched.
        }

        $agent = $this->onlySpan('invoke_agent support');
        self::assertSame(StatusCode::STATUS_ERROR, $agent->getStatus()->getCode());
        self::assertSame(RuntimeException::class, $agent->getAttributes()->toArray()['error.type']);
        self::assertStringNotContainsString('prompt text leaked', $this->everythingRecorded());
    }

    #[Test]
    public function withoutAnSdkTheRunIsUnchanged(): void
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnOnConsecutiveCalls(
            $this->response('', [new ToolCall('call_1', 'fetch_logs', [])]),
            $this->response('all done'),
        );
        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs', 'LOGS')]));

        $result = $service->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration('support'), ToolExecutionContext::none(), null);

        self::assertSame('all done', $result->finalContent);
        self::assertSame('LOGS', $result->trace[0]->result);
    }

    #[Test]
    public function withTheSettingOffAnInstalledSdkReceivesNothing(): void
    {
        $this->installOpenTelemetrySdk();
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnOnConsecutiveCalls(
            $this->response('', [new ToolCall('call_1', 'fetch_logs', [])]),
            $this->response('all done'),
        );
        $service = $this->service($mgr, new ToolRegistry([new FakeTool('fetch_logs')]), false);

        $service->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration('support'), ToolExecutionContext::none(), null);

        self::assertSame([], $this->finishedSpans());
    }

    private function service(LlmServiceManagerInterface $mgr, ToolRegistry $registry, bool $tracing = true): ToolLoopService
    {
        $policy = new ToolCallPolicy(
            $registry,
            new FakeToolAvailability($registry->names()),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        return new ToolLoopService($mgr, $registry, $policy, tracing: new ToolTelemetry($this->telemetry($tracing)));
    }

    /**
     * A configuration on a local provider, so the trust-zone gate permits the fake tools.
     */
    private function configuration(string $identifier): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);
        $configuration->setLlmModel($model);

        return $configuration;
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, ?array $toolCalls = null, int $promptTokens = 0, int $completionTokens = 0): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: UsageStatistics::fromTokens($promptTokens, $completionTokens),
            toolCalls: $toolCalls,
        );
    }

    private function failingTool(string $name, bool $throws): ToolInterface
    {
        return new class ($name, $throws) implements ToolInterface {
            public function __construct(private readonly string $name, private readonly bool $throws) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function($this->name, 'fails', ['type' => 'object', 'properties' => []]);
            }

            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                if ($this->throws) {
                    throw new RuntimeException('database password is hunter2', 7822289287);
                }

                return ToolResult::error('database password is hunter2');
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
}
