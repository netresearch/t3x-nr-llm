<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use ArrayObject;
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
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixtures\Process\ProcessPinProbeStub;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RefusingWriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A call its tool refuses whatever the approver decides does not suspend the
 * run for approval: it goes to the tool, which refuses it (ADR-262). A call
 * the tool would write, or about which the tool cannot say, still suspends.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceRefusesBeforeApprovalTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    private bool $processRun = false;

    /** Whether `refused_write` refuses; flipped to model a change between the pause and the resume. */
    private bool $refuses = true;

    /** @var ArrayObject<int, string> */
    private ArrayObject $writes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes = new ArrayObject();
    }

    #[Test]
    public function aCallItsToolRefusesRunsWithoutApprovalAndIsRefused(): void
    {
        $result = $this->service([$this->response('', [new ToolCall('c1', 'refused_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null);

        self::assertSame('done', $result->finalContent);
        self::assertSame(RefusingWriteTool::REFUSAL, $result->trace[0]->result);
        self::assertTrue($result->trace[0]->isError);
        self::assertSame([], $this->writes->getArrayCopy(), 'a call that skipped the approval wrote');
    }

    #[Test]
    public function aCallItsToolWouldWriteStillSuspends(): void
    {
        $state = $this->suspendOn([new ToolCall('c1', 'plain_write', ['id' => 'a'])]);

        self::assertSame(['c1'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertSame([], $this->writes->getArrayCopy());
    }

    #[Test]
    public function aToolThatCannotSayStillSuspends(): void
    {
        $state = $this->suspendOn([new ToolCall('c1', 'unsure_write', ['id' => 'a'])]);

        self::assertSame(['c1'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
    }

    /**
     * Beside a call that needs approval the turn suspends as one (ADR-132):
     * the refused call's card says it will be refused, and approving the turn
     * writes the other call only.
     */
    #[Test]
    public function inATurnThatSuspendsTheRefusedCallsCardSaysSoAndOnlyTheOtherCallWrites(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'refused_write', ['id' => 'b']),
        ]);

        self::assertSame(['c1', 'c2'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertSame(['would be refused'], $state->callPreviews[1]['lines'] ?? null);

        $captured = [];
        $this->service([$this->response('applied')], $captured)
            ->resume($state, true, $this->configuration(), $this->context());

        self::assertSame(['a'], $this->writes->getArrayCopy());
        self::assertSame(['WROTE', RefusingWriteTool::REFUSAL], $this->toolResults($captured[0]));
    }

    /**
     * The card said the call would be refused; when that no longer holds at
     * the resume, the card is stale and the run pauses again rather than
     * writing a call nobody approved (ADR-184).
     */
    #[Test]
    public function aRefusalThatNoLongerHoldsAtTheResumePausesAgain(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'refused_write', ['id' => 'b']),
        ]);

        $this->refuses = false;

        try {
            $this->service([$this->response('applied')])->resume($state, true, $this->configuration(), $this->context());
            self::fail('Expected the stale card to pause the run again.');
        } catch (ToolApprovalRequiredException $e) {
            self::assertSame(['would write'], $e->state->callPreviews[1]['lines'] ?? null);
        }

        self::assertSame([], $this->writes->getArrayCopy());
    }

    /**
     * The card showed the call refused, and the turn's earlier call removes the
     * reason before this one runs: it is still refused, with the reason the
     * approver read, and writes nothing nobody approved (ADR-266).
     */
    #[Test]
    public function aCallTheCardShowedRefusedStaysRefusedWhenAnEarlierCallLiftsTheReason(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'lift']),
            new ToolCall('c2', 'refused_until_lift', ['id' => 'b']),
        ]);

        self::assertSame(['would be refused'], $state->callPreviews[1]['lines'] ?? null);

        $captured = [];
        $this->service([$this->response('applied')], $captured)
            ->resume($state, true, $this->configuration(), $this->context());

        self::assertSame(['lift'], $this->writes->getArrayCopy(), 'the refused call wrote after the earlier call lifted its reason');
        self::assertSame(['WROTE', RefusingWriteTool::REFUSAL], $this->toolResults($captured[0]));
    }

    /**
     * A guided process proposes the call that needs approval, never the one
     * its tool refuses; that one runs beside the proposal and answers with
     * its own reason.
     */
    #[Test]
    public function aProcessTurnProposesTheCallThatNeedsApprovalAndRunsTheRefusedOne(): void
    {
        $this->processRun = true;

        $state = $this->suspendOn([
            new ToolCall('c1', 'refused_write', ['id' => 'b']),
            new ToolCall('c2', 'plain_write', ['id' => 'a']),
        ]);

        self::assertSame(['c2'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertSame([RefusingWriteTool::REFUSAL], $this->toolResults($state->messages));
        self::assertSame([], $this->writes->getArrayCopy());
    }

    #[Test]
    public function aProcessTurnWhoseOnlyWriteIsRefusedDoesNotSuspend(): void
    {
        $this->processRun = true;

        $result = $this->service([$this->response('', [new ToolCall('c1', 'refused_write', ['id' => 'b'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null);

        self::assertSame(RefusingWriteTool::REFUSAL, $result->trace[0]->result);
        self::assertSame([], $this->writes->getArrayCopy());
    }

    /**
     * Beside a question, a call its tool refuses runs on the answer and is
     * refused with its own reason, not as a write nobody approved.
     */
    #[Test]
    public function besideAQuestionTheRefusedCallIsRefusedByItsTool(): void
    {
        try {
            $this->service([$this->response('', [new ToolCall('c1', 'ask_user', []), new ToolCall('c2', 'refused_write', ['id' => 'b'])])])
                ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null);
            self::fail('Expected the run to suspend for input.');
        } catch (ToolInputRequiredException $e) {
            $state = $e->state;
        }

        $captured = [];
        $this->service([$this->response('ok')], $captured)
            ->resumeWithInput($state, ['city' => 'Berlin'], $this->configuration(), $this->context());

        self::assertSame(RefusingWriteTool::REFUSAL, $this->toolResults($captured[0])[1] ?? null);
        self::assertSame([], $this->writes->getArrayCopy());
    }

    /**
     * @param list<ToolCall> $turn
     */
    private function suspendOn(array $turn): SuspendedRunState
    {
        try {
            $this->service([$this->response('', $turn)])
                ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }

        self::fail('Expected the run to suspend for approval.');
    }

    /**
     * The contents of the tool results among the messages, in order.
     *
     * @param array<int, mixed> $messages
     *
     * @return list<string>
     */
    private function toolResults(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            $array = $message instanceof ChatMessage ? $message->toTranscriptArray() : $message;
            if (is_array($array) && ($array['role'] ?? null) === 'tool' && is_string($array['content'] ?? null)) {
                $results[] = $array['content'];
            }
        }

        return $results;
    }

    private function context(): ToolExecutionContext
    {
        return new ToolExecutionContext(AiActorContext::anonymous(), null, new AgentRunReference(1, 'run'));
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
                    throw new RuntimeException('Scripted response queue underflow.', 1799262002);
                }

                return $next;
            },
        );

        $registry = new ToolRegistry([
            new FakeTool('read_page', 'PAGE'),
            new RefusingWriteTool('refused_write', $this->refuses, $this->writes),
            new RefusingWriteTool('plain_write', false, $this->writes),
            new RefusingWriteTool('unsure_write', false, $this->writes, true),
            new RefusingWriteTool('refused_until_lift', true, $this->writes, until: 'lift'),
            new FakeInputTool('ask_user'),
        ]);
        $policy = new ToolCallPolicy(
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
            processPinProbe: new ProcessPinProbeStub(everyRun: $this->processRun),
        );
    }

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
}
