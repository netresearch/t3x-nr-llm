<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use ArrayObject;
use LogicException;
use Netresearch\NrLlm\Domain\Enum\ApprovalDenialReason;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationTarget;
use Netresearch\NrLlm\Service\Agent\Process\ProcessPinProbe;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\AskChoiceTool;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixtures\Process\ProcessPinProbeStub;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeRemoteTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\PreviewingApprovalTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The turn rules of a guided process (ADR-214 item 9), the denial reason it
 * renders, and the per-call input schema of the choice builtin.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceProcessTurnTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    private const RUN = 'process-run';

    /** What the probe says of the loop's pins: these tests are about the rules, not the pins. */
    private bool $processRun = true;

    /** The invocation policy the loop is built with; null builds it without one. */
    private ?ToolInvocationPolicyInterface $invocationPolicy = null;

    /**
     * One approval-bound call is pending — the first that writes, though a
     * write comes later in the turn — and every other call is settled beside
     * it in turn order: the read runs, everything else gets the error it is
     * owed, and nothing that writes runs.
     */
    #[Test]
    public function aProcessTurnSuspendsOnItsFirstWriteAndSettlesTheRest(): void
    {
        $turn = [
            new ToolCall('c1', 'confirm_read', []),
            new ToolCall('c2', 'read_page', []),
            new ToolCall('c3', 'write_one', ['uid' => 1]),
            new ToolCall('c4', 'write_two', ['uid' => 2]),
            new ToolCall('c5', 'ask_user', []),
            new ToolCall('c6', 'remote_thing', []),
            new ToolCall('c7', 'never_registered', []),
        ];

        $state = $this->suspendOn($turn, $this->pinned());

        self::assertSame(['c3'], array_map(static fn(ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertSame([], $state->callPreviews, 'the card previews the pending call only, not the approval-bound read beside it');

        // The stored turn puts the pending call last, and the results of the
        // others follow it in that order: a provider that pairs results by
        // position (Ollama) then pairs the pending result, appended on resume,
        // with the pending call.
        $assistant = $state->messages[1];
        self::assertSame(['c1', 'c2', 'c4', 'c5', 'c6', 'c7', 'c3'], $this->callIds($assistant));
        $results = array_slice($state->messages, 2);
        self::assertSame(['c1', 'c2', 'c4', 'c5', 'c6', 'c7'], array_map(fn(array $m): string => $this->string($m['tool_call_id'] ?? null), $results));

        $byId = array_combine(
            array_map(fn(array $m): string => $this->string($m['tool_call_id'] ?? null), $results),
            array_map(fn(array $m): string => $this->string($m['content'] ?? null), $results),
        );
        self::assertStringContainsString('one approval per turn in a process', $byId['c1'], 'a second approval-bound call, though a read');
        self::assertSame('PAGE', $byId['c2'], 'a read that needs no approval runs before the suspend');
        self::assertStringContainsString('one approval per turn in a process', $byId['c4']);
        self::assertStringContainsString('requires user input that was not provided', $byId['c5']);
        self::assertStringContainsString('not executed while a proposal waits for approval', $byId['c6'], 'a remote tool never runs early');
        self::assertStringContainsString('unknown tool', $byId['c7'], 'a call the run was not offered is refused as in any turn');
    }

    /**
     * The reads that run beside a proposal go through the invocation policy
     * like every other call, and the history the suspension carries records
     * them: a read the policy refuses is not executed and enters as denied,
     * a read it allows runs and enters as ok, and every call refused beside
     * the proposal enters as denied. The suspension also carries the
     * initiating actor, which a resume of the run requires.
     */
    #[Test]
    public function theReadsBesideAProposalGoThroughTheInvocationPolicy(): void
    {
        $turn    = [new ToolCall('c1', 'read_page', []), new ToolCall('c2', 'confirm_read', []), new ToolCall('c3', 'write_one', ['uid' => 1])];
        $context = $this->pinned();

        foreach (['refused' => false, 'allowed' => true] as $case => $allowed) {
            /** @var ArrayObject<int, string> $decided */
            $decided                = new ArrayObject();
            $this->invocationPolicy = new class ($allowed, $decided) implements ToolInvocationPolicyInterface {
                /**
                 * @param ArrayObject<int, string> $decided
                 */
                public function __construct(private readonly bool $allowed, private ArrayObject $decided) {}

                public function resolveTarget(ToolCall $call, ToolExecutionContext $context): ?ToolInvocationTarget
                {
                    return null;
                }

                public function decide(ToolInvocationContext $context): ToolInvocationDecision
                {
                    $this->decided[] = $context->toolName;

                    return $this->allowed ? ToolInvocationDecision::allow() : ToolInvocationDecision::deny('test')->forRule('beside');
                }
            };

            $state = $this->suspendOn($turn, $context);

            self::assertSame(['read_page'], $decided->getArrayCopy(), $case . ': only the read beside the proposal is decided now');
            $read = $this->content($state->messages[2]);
            if ($allowed) {
                self::assertSame('PAGE', $read, $case);
            } else {
                self::assertStringStartsWith('Error: invocation denied (rule: beside', $read, $case);
            }

            self::assertNotNull($state->invocationHistory, $case);
            self::assertSame(
                [['read_page', $allowed ? 'ok' : 'denied'], ['confirm_read', 'denied']],
                array_map(static fn(array $e): array => [$e['tool'], $e['outcome']], $state->invocationHistory->entries),
                $case,
            );
            self::assertSame($context->actor, $state->initiatingActor, $case);
            self::assertSame(self::RUN, $state->initiatingRunUuid, $case);
        }
    }

    /**
     * A turn without a write suspends on its first approval-bound call of any
     * kind.
     */
    #[Test]
    public function aProcessTurnWithoutAWriteSuspendsOnItsFirstApprovalBoundCall(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'read_page', []),
            new ToolCall('c2', 'confirm_read', []),
            new ToolCall('c3', 'confirm_other', []),
        ], $this->pinned());

        self::assertSame(['c2'], array_map(static fn(ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertCount(4, $state->messages, 'user, assistant, and the results of c1 and c3');
        self::assertCount(1, $state->callPreviews, 'the card previews the pending call only');
        self::assertSame(['index' => 0, 'tool' => 'confirm_read'], array_intersect_key($state->callPreviews[0], ['index' => 0, 'tool' => '']));
    }

    /**
     * The other direction: a run whose pins hold no process suspends before
     * any call of the turn, with the whole turn pending.
     */
    #[Test]
    public function anyOtherRunSuspendsBeforeAnyCallWithTheWholeTurnPending(): void
    {
        $turn = [
            new ToolCall('c1', 'read_page', []),
            new ToolCall('c2', 'write_one', ['uid' => 1]),
            new ToolCall('c3', 'write_two', ['uid' => 2]),
        ];

        $this->processRun = false;
        $state            = $this->suspendOn($turn, $this->pinned());

        self::assertSame(['c1', 'c2', 'c3'], array_map(static fn(ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertCount(2, $state->messages, 'nothing ran before the suspend');
        self::assertSame(['c1', 'c2', 'c3'], $this->callIds($state->messages[1]));
    }

    /**
     * A process turn that needs no approval runs like any other turn.
     */
    #[Test]
    public function aProcessTurnWithoutAnApprovalBoundCallRunsAsUsual(): void
    {
        $result = $this->service([$this->response('', [new ToolCall('c1', 'read_page', [])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->pinned(), null);

        self::assertSame('done', $result->finalContent);
        self::assertSame('PAGE', $result->trace[0]->result);
    }

    /**
     * Approving the pending call runs it, and its result is appended after the
     * results the suspend stored, matching its place at the end of the turn.
     */
    #[Test]
    public function approvingRunsThePendingCallAndAppendsItsResultLast(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'write_one', ['uid' => 1]),
            new ToolCall('c2', 'read_page', []),
        ], $this->pinned());

        $captured = [];
        $this->service([$this->response('applied')], $captured)
            ->resume($state, true, $this->configuration(), $this->pinned());

        $sent    = $captured[0];
        $results = array_values(array_filter($sent, fn(mixed $m): bool => $this->role($m) === 'tool'));
        self::assertSame(['c2', 'c1'], $this->callIds($sent[1]));
        self::assertSame(['PAGE', 'WROTE'], array_map($this->content(...), $results));
    }

    /**
     * A reason travels to the model as a fixed token beside decided_by, on a
     * pending write only, and replaces the advice to ask again.
     */
    #[Test]
    public function aDenialReasonIsRenderedOnAPendingWrite(): void
    {
        $writeState = new SuspendedRunState([['role' => 'user', 'content' => 'go']], [(new ToolCall('c1', 'write_one', ['uid' => 1]))->toArray()], 1, 0, 0, null, []);

        $variant = $this->deniedResult($writeState, ApprovalDenialReason::VARIANT);
        self::assertStringContainsString('(decided_by: run_owner, reason: ' . ToolLoopServiceInterface::DENIAL_REASON_VARIANT . ')', $variant);
        self::assertStringContainsString('propose a different version of this change', $variant);
        self::assertStringNotContainsString('ask whether the change should be proposed again', $variant);

        $skip = $this->deniedResult($writeState, ApprovalDenialReason::SKIP);
        self::assertStringContainsString('(decided_by: run_owner, reason: ' . ToolLoopServiceInterface::DENIAL_REASON_SKIP . ')', $skip);
        self::assertStringContainsString('do not propose this change again', $skip);

        $none = $this->deniedResult($writeState, null);
        self::assertStringContainsString('(decided_by: run_owner)', $none, "a denial without a reason keeps today's text");
        self::assertStringContainsString('ask whether the change should be proposed again', $none);
    }

    /**
     * The other direction: a pending call that is no write is no proposal, and
     * keeps the plain denial whatever reason the caller passed.
     */
    #[Test]
    public function aDenialReasonIsNotRenderedOnACallThatIsNoWrite(): void
    {
        $readState = new SuspendedRunState([['role' => 'user', 'content' => 'go']], [(new ToolCall('c1', 'confirm_read', []))->toArray()], 1, 0, 0, null, []);

        $result = $this->deniedResult($readState, ApprovalDenialReason::SKIP);

        self::assertStringContainsString('(decided_by: run_owner)', $result);
        self::assertStringNotContainsString('reason:', $result);
    }

    /**
     * The choice builtin suspends with a schema built from the call: its
     * options become the enum of the one answer.
     */
    #[Test]
    public function theChoiceBuiltinSuspendsWithTheOptionsOfItsCall(): void
    {
        $call = new ToolCall('c1', AskChoiceTool::NAME, ['question' => 'Which page?', 'options' => ['Home', 'About']]);

        try {
            $this->service([$this->response('', [$call])])
                ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(null), null);
            self::fail('Expected the run to suspend for input.');
        } catch (ToolInputRequiredException $e) {
            $schema = $e->state->inputSchema;
        }

        self::assertSame(['Home', 'About'], $schema['properties'][AskChoiceTool::ANSWER]['enum'] ?? null);
        self::assertSame('Which page?', $schema['properties'][AskChoiceTool::ANSWER]['title'] ?? null);
    }

    /**
     * Arguments the choice cannot be asked with are the model's mistake: no
     * suspension, and the call's result says what is wrong.
     */
    #[Test]
    public function aChoiceWithArgumentsItCannotAskWithIsAnErrorResultNotAPause(): void
    {
        $call = new ToolCall('c1', AskChoiceTool::NAME, ['question' => 'Which page?', 'options' => ['Only one']]);

        $result = $this->service([$this->response('', [$call]), $this->response('sorry')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(null), null);

        self::assertSame('sorry', $result->finalContent);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringContainsString('2 to 10 options', $result->trace[0]->result);
    }

    /**
     * The resume re-checks the enum: an answer that is not one of the options
     * never reaches the tool.
     */
    #[Test]
    public function anAnswerOutsideTheOptionsIsRefusedAtResume(): void
    {
        $call  = new ToolCall('c1', AskChoiceTool::NAME, ['question' => 'Which page?', 'options' => ['Home', 'About']]);
        $state = new SuspendedRunState(
            [['role' => 'user', 'content' => 'go']],
            [$call->toArray()],
            1,
            0,
            0,
            null,
            [],
            inputToolName: AskChoiceTool::NAME,
            inputSchema: (new AskChoiceTool())->inputSchemaFor($call->arguments),
        );

        $captured = [];
        $this->service([$this->response('noted')], $captured)
            ->resumeWithInput($state, [AskChoiceTool::ANSWER => 'About'], $this->configuration(), $this->context(null));
        self::assertSame('The user picked: About', $this->content($this->lastSent($captured)));

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1784600106);
        $this->service([$this->response('noted')])
            ->resumeWithInput($state, [AskChoiceTool::ANSWER => 'Contact'], $this->configuration(), $this->context(null));
    }

    /**
     * @param list<ToolCall> $turn
     */
    private function suspendOn(array $turn, ToolExecutionContext $context): SuspendedRunState
    {
        try {
            $this->service([$this->response('', $turn)])
                ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $context, null);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }

        self::fail('Expected the run to suspend for approval.');
    }

    private function deniedResult(SuspendedRunState $state, ?ApprovalDenialReason $reason): string
    {
        $captured = [];
        $context  = new ToolExecutionContext(AiActorContext::backendUser(5), null, new AgentRunReference(1, self::RUN));
        $this->service([$this->response('ok')], $captured)
            ->resume($state, false, $this->configuration(), $context, null, null, 5, $reason);

        return $this->content($this->lastSent($captured));
    }

    /**
     * The last message of the first request the provider received.
     *
     * @param list<array<int, mixed>> $captured
     */
    private function lastSent(array $captured): mixed
    {
        self::assertArrayHasKey(0, $captured);
        $messages = $captured[0];
        self::assertNotSame([], $messages);

        return end($messages);
    }

    private function pinned(): ToolExecutionContext
    {
        return $this->context(new AgentRunReference(1, self::RUN));
    }

    private function context(?AgentRunReference $run): ToolExecutionContext
    {
        return new ToolExecutionContext(AiActorContext::anonymous(), null, $run);
    }

    /**
     * @param list<CompletionResponse> $queue
     * @param list<array<int, mixed>>  $captured
     */
    private function service(array $queue, array &$captured = []): ToolLoopService
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (array $messages) use (&$queue, &$captured): CompletionResponse {
                $captured[] = $messages;
                $next       = array_shift($queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1791600901);
                }

                return $next;
            },
        );

        $registry = new ToolRegistry($this->tools());
        $policy   = new ToolCallPolicy(
            $registry,
            new FakeToolAvailability($registry->names()),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        return new ToolLoopService(
            $mgr,
            $registry,
            $policy,
            previewTranslator: $this->englishTranslator(),
            invocationPolicy: $this->invocationPolicy,
            processPinProbe: $this->probe(),
        );
    }

    private function probe(): ProcessPinProbe
    {
        return new ProcessPinProbeStub(everyRun: $this->processRun);
    }

    /**
     * @return list<ToolInterface>
     */
    private function tools(): array
    {
        return [
            new FakeTool('read_page', 'PAGE'),
            new FakeTool('write_one', 'WROTE', true, false, 'test', [], ToolEffect::NON_IDEMPOTENT_WRITE),
            new FakeTool('write_two', 'WROTE TOO', true, false, 'test', [], ToolEffect::IDEMPOTENT_WRITE),
            new PreviewingApprovalTool('confirm_read'),
            new PreviewingApprovalTool('confirm_other'),
            new FakeInputTool('ask_user'),
            new FakeRemoteTool('remote_thing'),
            new AskChoiceTool(),
        ];
    }

    /**
     * A configuration whose provider sits in the LOCAL trust zone, so the
     * data-class axis of the gate permits every fixture tool.
     */
    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: UsageStatistics::fromTokens(1, 1),
            toolCalls: $toolCalls,
        );
    }

    /**
     * The call ids of an assistant turn, in stored order.
     *
     * @return list<string>
     */
    private function callIds(mixed $turn): array
    {
        $calls = $turn instanceof ChatMessage ? $turn->toTranscriptArray()['tool_calls'] ?? [] : (is_array($turn) ? $turn['tool_calls'] ?? [] : []);
        self::assertIsArray($calls);

        return array_values(array_map(fn(mixed $c): string => $this->string(is_array($c) ? $c['id'] ?? null : null), $calls));
    }

    private function role(mixed $message): string
    {
        $array = $message instanceof ChatMessage ? $message->toTranscriptArray() : $message;

        return is_array($array) && is_string($array['role'] ?? null) ? $array['role'] : '';
    }

    private function content(mixed $message): string
    {
        $array = $message instanceof ChatMessage ? $message->toTranscriptArray() : $message;
        self::assertIsArray($array);

        return $this->string($array['content'] ?? null);
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
