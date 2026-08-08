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
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\TrustZoneResolver;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceAugmentationTest extends TestCase
{
    #[Test]
    public function dryRunAssemblesAndRecordsWithoutCallingTheProvider(): void
    {
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');
        $mgr->expects(self::never())->method('chatWithConfiguration');

        $trace  = new RunTrace();
        $result = $this->service($mgr)->runLoop(
            [['role' => 'user', 'content' => 'hi']],
            $this->localConfiguration(),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            $trace,
            new RunAugmentation(dryRun: true),
        );

        self::assertSame('', $result->finalContent);
        self::assertSame(0, $result->iterations);
        self::assertFalse($result->truncated);

        $steps = $trace->getSteps();
        self::assertCount(1, $steps);
        self::assertSame(RunStep::KIND_ASSEMBLED, $steps[0]->kind);
        self::assertNotNull($steps[0]->messagesSent);
    }

    #[Test]
    public function forcedSnippetIsAssembledAsALeadingSystemMessage(): void
    {
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->expects(self::never())->method('chatWithToolsForConfiguration');

        $snippet = new PromptSnippet();
        $snippet->setName('formal-tone');
        $snippet->setSnippet('Use the formal register.');

        $trace = new RunTrace();
        $this->service($mgr)->runLoop(
            [['role' => 'user', 'content' => 'translate this']],
            $this->localConfiguration(),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            $trace,
            new RunAugmentation(forcedSnippets: [$snippet], dryRun: true),
        );

        $messages = $trace->getSteps()[0]->messagesSent;
        self::assertNotNull($messages);
        // Snippet lands as the first (leading) system message, before the user turn.
        self::assertSame('system', $messages[0]['role']);
        $content = $messages[0]['content'];
        self::assertIsString($content);
        self::assertStringContainsString('Use the formal register.', $content);
        self::assertSame('user', $messages[array_key_last($messages)]['role']);
    }

    #[Test]
    public function liveRunRecordsOneLlmStepPerRoundTrip(): void
    {
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturn(new CompletionResponse(
            content: 'done',
            model: 'm',
            usage: UsageStatistics::fromTokens(10, 4),
        ));

        $trace  = new RunTrace();
        $result = $this->service($mgr)->runLoop(
            [['role' => 'user', 'content' => 'hi']],
            $this->localConfiguration(),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            $trace,
            new RunAugmentation(),
        );

        self::assertSame('done', $result->finalContent);
        $steps = $trace->getSteps();
        // Outbound request first (streamed before the provider call), then the
        // response — the messages live on the request, the tokens on the response.
        self::assertCount(2, $steps);
        self::assertSame(RunStep::KIND_REQUEST, $steps[0]->kind);
        self::assertSame(1, $steps[0]->round);
        self::assertNotNull($steps[0]->messagesSent);
        self::assertSame(RunStep::KIND_LLM, $steps[1]->kind);
        self::assertSame('done', $steps[1]->content);
        self::assertSame(10, $steps[1]->promptTokens);
        self::assertNull($steps[1]->messagesSent);
    }

    #[Test]
    public function toolResultWithInvalidUtf8IsCoercedSoTheReRequestCannotThrow(): void
    {
        // Round 1 asks for the tool; round 2 (after the tool result is appended)
        // answers. Before the fix the round-2 request json_encode() threw a
        // JsonException on the tool's non-UTF-8 bytes.
        $mgr = $this->createMock(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnOnConsecutiveCalls(
            new CompletionResponse(
                content: '',
                model: 'm',
                usage: UsageStatistics::fromTokens(5, 2),
                toolCalls: [new ToolCall('call_1', 'bad', [])],
            ),
            new CompletionResponse(content: 'final', model: 'm', usage: UsageStatistics::fromTokens(3, 1)),
        );

        // A tool echoing raw bytes (log/env/phpinfo output) that are not valid UTF-8.
        $registry = new ToolRegistry([new FakeTool('bad', "before \xFF\xFE after")]);
        $availability = new FakeToolAvailability($registry->names());
        $service      = new ToolLoopService(
            $mgr,
            $registry,
            new ToolCallPolicy(
                $registry,
                $availability,
                new AllowedToolsResolver(new SkillComposer(), $registry),
                new ToolDataClassResolver($registry),
                new TrustZoneResolver(),
                new DataClassEnforcementResolver(),
            ),
            skillInjection: new SkillInjectionService(new SkillComposer(), new NullLogger()),
            snippetComposer: new PromptSnippetComposer(),
        );

        $trace  = new RunTrace();
        $result = $service->runLoop(
            [['role' => 'user', 'content' => 'go']],
            $this->localConfiguration(),
            ToolExecutionContext::none(),
            ['bad'],
            null,
            null,
            $trace,
            new RunAugmentation(),
        );

        self::assertSame('final', $result->finalContent);

        $toolStep = array_values(array_filter(
            $trace->getSteps(),
            static fn(RunStep $s): bool => $s->kind === RunStep::KIND_TOOL,
        ))[0] ?? null;
        self::assertNotNull($toolStep);
        self::assertNotNull($toolStep->toolResult);
        self::assertTrue(mb_check_encoding($toolStep->toolResult, 'UTF-8'), 'tool result must be valid UTF-8');
        // The whole trace must be JSON-encodable (this is what the controller does).
        self::assertIsString(json_encode(
            array_map(static fn(RunStep $s): array => $s->toArray(), $trace->getSteps()),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function service(LlmServiceManagerInterface $mgr): ToolLoopService
    {
        $registry = new ToolRegistry([new FakeTool('noop')]);

        $availability = new FakeToolAvailability($registry->names());

        return new ToolLoopService(
            $mgr,
            $registry,
            new ToolCallPolicy(
                $registry,
                $availability,
                new AllowedToolsResolver(new SkillComposer(), $registry),
                new ToolDataClassResolver($registry),
                new TrustZoneResolver(),
                new DataClassEnforcementResolver(),
            ),
            skillInjection: new SkillInjectionService(new SkillComposer(), new NullLogger()),
            snippetComposer: new PromptSnippetComposer(),
        );
    }

    /**
     * A configuration whose provider sits in the LOCAL trust zone.
     *
     * {@see FakeTool} declares the group `test`, absent from the data-class
     * group defaults and therefore failing closed to SECRET_ADJACENT. A
     * configuration without a provider fails closed to EXTERNAL_GLOBAL, whose
     * ceiling is EDITOR_CONTENT, so the composite gate would withhold every fake
     * tool for a reason these tests are not about (ADR-094).
     */
    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }

}
