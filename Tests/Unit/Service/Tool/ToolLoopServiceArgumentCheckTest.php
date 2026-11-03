<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\RequiresApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolArgumentChecker;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A call whose arguments do not fit the schema its tool was offered with is
 * answered with what does not fit, and nothing of its turn runs (ADR-246).
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(ToolArgumentChecker::class)]
#[CoversClass(RunTrace::class)]
#[CoversClass(RunStep::class)]
final class ToolLoopServiceArgumentCheckTest extends TestCase
{
    private const SCHEMA = [
        'type'       => 'object',
        'properties' => ['uid' => ['type' => 'integer']],
        'required'   => ['uid'],
    ];

    private RunTrace $trace;

    #[Test]
    public function aMissingRequiredArgumentIsNamedAndTheToolDoesNotRun(): void
    {
        $runs   = new stdClass();
        $result = $this->runTurns(
            [$this->tool('get_page', $runs)],
            [[ToolCall::function('c1', 'get_page', [])]],
        );

        self::assertSame(0, $runs->count ?? 0);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringContainsString('"uid" is required.', $result->trace[0]->result);
        self::assertStringContainsString('schema of tool "get_page"', $result->trace[0]->result);
    }

    #[Test]
    public function theCorrectedCallRunsInTheNextRound(): void
    {
        $runs   = new stdClass();
        $result = $this->runTurns(
            [$this->tool('get_page', $runs)],
            [
                [ToolCall::function('c1', 'get_page', ['uid' => 'seven'])],
                [ToolCall::function('c2', 'get_page', ['uid' => 7])],
            ],
        );

        self::assertSame(1, $runs->count ?? 0);
        self::assertStringContainsString('"uid" must be an integer, not the text "seven".', $result->trace[0]->result);
        self::assertFalse($result->trace[1]->isError);
        self::assertSame('ran', $result->trace[1]->result);
        self::assertSame(3, $result->iterations, 'one refused round, one that ran, one that answered');
    }

    #[Test]
    public function aNumericTextForAnIntegerStillRuns(): void
    {
        $runs = new stdClass();
        $this->runTurns(
            [$this->tool('get_page', $runs)],
            [[ToolCall::function('c1', 'get_page', ['uid' => '5'])]],
        );

        self::assertSame(1, $runs->count ?? 0, 'A tool reads "5" as 5 today; refusing it would cost a round.');
    }

    #[Test]
    public function anApprovalBoundCallWithMismatchedArgumentsIsRefusedInsteadOfAwaitingApproval(): void
    {
        $runs   = new stdClass();
        $result = $this->runTurns(
            [$this->tool('update_page', $runs, approval: true)],
            [[ToolCall::function('c1', 'update_page', ['uid' => ['a', 'list']])]],
        );

        self::assertSame(0, $runs->count ?? 0);
        self::assertStringContainsString('"uid" must be an integer, not a list.', $result->trace[0]->result);
    }

    #[Test]
    public function aFittingCallBesideAMismatchedOneDoesNotRunEither(): void
    {
        $good   = new stdClass();
        $bad    = new stdClass();
        $result = $this->runTurns(
            [$this->tool('get_page', $good), $this->tool('get_tree', $bad)],
            [[
                ToolCall::function('c1', 'get_page', ['uid' => 1]),
                ToolCall::function('c2', 'get_tree', []),
            ]],
        );

        self::assertSame(0, $good->count ?? 0);
        self::assertSame(0, $bad->count ?? 0);
        self::assertSame(
            [RunStep::REFUSAL_TURN, RunStep::REFUSAL_ARGUMENTS],
            array_values(array_map(
                static fn (RunStep $step): ?string => $step->toolRefusal,
                array_filter($this->trace->getSteps(), static fn (RunStep $step): bool => $step->kind === RunStep::KIND_TOOL),
            )),
            'The refusal is recorded as a code a metadata-only run keeps.',
        );
        self::assertStringStartsWith('Not run: another call in this turn', $result->trace[0]->result);
        self::assertStringContainsString('Call "get_page" again', $result->trace[0]->result);
        self::assertStringContainsString('"uid" is required.', $result->trace[1]->result);
    }

    #[Test]
    public function aFittingTurnRunsUnchanged(): void
    {
        $runs   = new stdClass();
        $result = $this->runTurns(
            [$this->tool('get_page', $runs)],
            [[ToolCall::function('c1', 'get_page', ['uid' => 3])]],
        );

        self::assertSame(1, $runs->count ?? 0);
        self::assertSame('ran', $result->trace[0]->result);
        self::assertSame(2, $result->iterations);
    }

    /**
     * @param list<ToolInterface>  $tools
     * @param list<list<ToolCall>> $turns the tool calls of each round; a final round answers in text
     */
    private function runTurns(array $tools, array $turns): ToolLoopResult
    {
        $responses = array_map(
            static fn (array $calls): CompletionResponse => new CompletionResponse('', 'test-model', UsageStatistics::fromTokens(1, 1), toolCalls: $calls),
            $turns,
        );
        $responses[] = new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1));

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnOnConsecutiveCalls(...$responses);

        $names   = array_map(static fn (ToolInterface $t): string => $t->getSpec()->name, $tools);
        $service = new ToolLoopService($mgr, new ToolRegistry($tools), $this->policyAllowing($names));

        $this->trace = new RunTrace();

        return $service->runLoop(
            [['role' => 'user', 'content' => 'do it']],
            $this->localConfiguration(),
            ToolExecutionContext::none(),
            null,
            maxIterations: 5,
            runTrace: $this->trace,
        );
    }

    private function tool(string $name, stdClass $runs, bool $approval = false): ToolInterface
    {
        $schema = self::SCHEMA;

        if ($approval) {
            return new class ($name, $schema, $runs) implements ToolInterface, RequiresApprovalInterface {
                /**
                 * @param array<string, mixed> $schema
                 */
                public function __construct(private readonly string $name, private readonly array $schema, private readonly stdClass $runs) {}

                public function getSpec(): ToolSpec
                {
                    return ToolSpec::function($this->name, 'writes', $this->schema);
                }

                public function execute(array $arguments, ToolExecutionContext $context): ToolResult
                {
                    $this->runs->count = (is_int($this->runs->count ?? null) ? $this->runs->count : 0) + 1;

                    return ToolResult::text('ran');
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

        return new class ($name, $schema, $runs) implements ToolInterface {
            /**
             * @param array<string, mixed> $schema
             */
            public function __construct(private readonly string $name, private readonly array $schema, private readonly stdClass $runs) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function($this->name, 'reads', $this->schema);
            }

            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                $this->runs->count = (is_int($this->runs->count ?? null) ? $this->runs->count : 0) + 1;

                return ToolResult::text('ran');
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

    /**
     * @param list<string> $names
     */
    private function policyAllowing(array $names): ToolCallPolicyInterface
    {
        return new class ($names) implements ToolCallPolicyInterface {
            /**
             * @param list<string> $names
             */
            public function __construct(private readonly array $names) {}

            public function skillAllowListForRun(LlmConfiguration $configuration, array $forcedSkills = []): SkillToolAllowList
            {
                return new SkillToolAllowList(null);
            }

            public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): ToolPolicyDecision
            {
                return $this->decision($toolName);
            }

            public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): array
            {
                return $requested === null ? $this->names : array_values(array_intersect($requested, $this->names));
            }

            public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): array
            {
                return array_map($this->decision(...), $this->names);
            }

            private function decision(string $name): ToolPolicyDecision
            {
                return new ToolPolicyDecision(
                    toolName: $name,
                    allowed: true,
                    dataClass: ToolDataClass::PUBLIC_CONTENT,
                    zone: TrustZone::LOCAL,
                    ceiling: ToolDataClass::SECRET_ADJACENT,
                    reason: ToolDenialReason::NONE,
                );
            }
        };
    }

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
