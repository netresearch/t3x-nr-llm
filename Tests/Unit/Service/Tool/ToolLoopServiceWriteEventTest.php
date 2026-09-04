<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RecordingEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A successful editorial write is announced to consumers, once, and only when
 * it can be attributed (ADR-187, #896).
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceWriteEventTest extends TestCase
{
    private const RUN_UUID = 'd1f0be47-0000-4000-8000-0000000000aa';

    #[Test]
    public function aSuccessfulWriteIsAnnouncedOnceWithItsRecordAndKind(): void
    {
        $dispatcher = new RecordingEventDispatcher();

        $this->runWriteOf(
            new FakeTool(
                'create_page_draft',
                'Created page [42].',
                effect: ToolEffect::NON_IDEMPOTENT_WRITE,
                writeTarget: new RecordReference('pages', 42),
                writeKind: WriteKind::CREATED,
            ),
            $dispatcher,
            new AgentRunReference(91, self::RUN_UUID),
        );

        self::assertCount(1, $dispatcher->dispatched);
        $event = $dispatcher->dispatched[0];
        self::assertInstanceOf(AfterAiRecordWrittenEvent::class, $event);
        self::assertSame(self::RUN_UUID, $event->correlationId);
        self::assertSame('pages:42', (string)$event->record);
        self::assertSame(WriteKind::CREATED, $event->kind);
    }

    /**
     * The 41 read-only builtins must stay silent. A tool that returns no write
     * target has written nothing to announce, and an event per tool call would
     * make the signal useless to exactly the consumer it exists for.
     */
    #[Test]
    public function aToolThatWroteNothingAnnouncesNothing(): void
    {
        $dispatcher = new RecordingEventDispatcher();

        $this->runWriteOf(
            new FakeTool('fetch_logs', 'LOGS'),
            $dispatcher,
            new AgentRunReference(91, self::RUN_UUID),
        );

        self::assertSame([], $dispatcher->dispatched);
    }

    /**
     * A bare loop consumer drives the loop without a persisted run, so there is
     * no correlation id to name. Dispatching an unattributable write would put
     * a row in a consumer's audit trail that points at no run — worse than the
     * silence, because it looks like a record.
     */
    #[Test]
    public function aWriteWithoutAPersistedRunIsNotAnnounced(): void
    {
        $dispatcher = new RecordingEventDispatcher();

        $this->runWriteOf(
            new FakeTool(
                'create_page_draft',
                'Created page [42].',
                effect: ToolEffect::NON_IDEMPOTENT_WRITE,
                writeTarget: new RecordReference('pages', 42),
                writeKind: WriteKind::CREATED,
            ),
            $dispatcher,
            null,
        );

        self::assertSame([], $dispatcher->dispatched);
    }

    /**
     * The loop's collaborators are optional by construction; the lean wiring
     * used by most tests constructs it without a dispatcher. That must remain a
     * silent no-op rather than a fatal, which is the property this asserts.
     */
    #[Test]
    public function aLoopWithoutADispatcherStillPerformsTheWrite(): void
    {
        $tool    = $this->writingTool();
        $service = $this->serviceFor($tool, null);
        $context = $this->contextFor(new AgentRunReference(91, self::RUN_UUID));
        $trace   = new RunTrace();

        $service->resume($this->suspend($service, $context, 'create_page_draft'), true, $this->localConfiguration(), $context, null, $trace);

        // The trace, not the final content: the final content comes from the
        // stubbed closing turn and would read 'all done' even if the tool had
        // never run.
        self::assertSame(['Created page [42].'], $this->toolResultsIn($trace));
    }

    /**
     * A consumer's listener runs after the write has landed and after a human
     * has approved it. Letting it take the run down would turn a completed
     * editorial write into a failed one — and the model's next move on a failed
     * write is to try it again.
     */
    #[Test]
    public function aThrowingListenerDoesNotFailTheRun(): void
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public bool $reached = false;

            public function dispatch(object $event): object
            {
                $this->reached = true;

                throw new RuntimeException('the listener is broken', 1788500002);
            }
        };

        $service = $this->serviceFor($this->writingTool(), $dispatcher);
        $context = $this->contextFor(new AgentRunReference(91, self::RUN_UUID));
        $trace   = new RunTrace();

        $service->resume($this->suspend($service, $context, 'create_page_draft'), true, $this->localConfiguration(), $context, null, $trace);

        // Both halves, or the test would pass on a dispatch that never happened:
        // the listener WAS reached, and the write still completed.
        self::assertTrue($dispatcher->reached);
        self::assertSame(['Created page [42].'], $this->toolResultsIn($trace));
    }

    /**
     * Drives the given tool the way production does and returns the run's
     * result.
     *
     * Suspend, then resume approved — not one straight loop round.
     * {@see \Netresearch\NrLlm\Service\Tool\ToolApprovalRule::requiresApproval()}
     * makes EVERY tool declaring a write effect approval-bound, so a write
     * never executes on the first pass: the loop throws before reaching the
     * tool. A test that only called `runLoop()` would assert about a code path
     * no editorial write ever takes.
     */
    private function runWriteOf(FakeTool $tool, ?RecordingEventDispatcher $dispatcher, ?AgentRunReference $run): ToolLoopResult
    {
        $name = $tool->getSpec()->name;
        $service = $this->serviceFor($tool, $dispatcher);

        $context = $this->contextFor($run);

        // A write resumes; a read runs straight through. The resumed call's
        // own trace entry belongs to the pre-suspend segment and is recorded on
        // the RunTrace, not on the continuation's returned trace — which is why
        // the write assertions here read the dispatcher and the final content
        // rather than counting trace rows.
        return $tool->getEffect()->isWrite()
            ? $service->resume($this->suspend($service, $context, $name), true, $this->localConfiguration(), $context)
            : $service->runLoop([['role' => 'user', 'content' => 'do it']], $this->localConfiguration(), $context, null);
    }

    /**
     * The builtin-shaped writer these tests drive: approval-bound, and naming
     * the record it created.
     */
    private function writingTool(): FakeTool
    {
        return new FakeTool(
            'create_page_draft',
            'Created page [42].',
            effect: ToolEffect::NON_IDEMPOTENT_WRITE,
            writeTarget: new RecordReference('pages', 42),
            writeKind: WriteKind::CREATED,
        );
    }

    private function contextFor(?AgentRunReference $run): ToolExecutionContext
    {
        return $run instanceof AgentRunReference
            ? new ToolExecutionContext(AiActorContext::backendUser(42, true), null, $run)
            : ToolExecutionContext::none();
    }

    /**
     * The tool results the trace recorded, in order.
     *
     * @return list<string>
     */
    private function toolResultsIn(RunTrace $trace): array
    {
        $results = [];
        foreach ($trace->getSteps() as $step) {
            if ($step->kind === RunStep::KIND_TOOL) {
                $results[] = (string)$step->toolResult;
            }
        }

        return $results;
    }

    /**
     * A loop wired to run exactly one call of $tool and then finish.
     */
    private function serviceFor(FakeTool $tool, ?EventDispatcherInterface $dispatcher): ToolLoopService
    {
        $name  = $tool->getSpec()->name;
        $queue = [
            new CompletionResponse('', 'test-model', UsageStatistics::fromTokens(1, 1), toolCalls: [new ToolCall('call_1', $name, [])]),
            new CompletionResponse('all done', 'test-model', UsageStatistics::fromTokens(1, 1)),
        ];

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback(
            static function () use (&$queue): CompletionResponse {
                $next = array_shift($queue);
                self::assertInstanceOf(CompletionResponse::class, $next);

                return $next;
            },
        );

        return new ToolLoopService($mgr, new ToolRegistry([$tool]), $this->policyAllowing($name), eventDispatcher: $dispatcher);
    }

    /**
     * The state the run suspends in when it asks for approval of $name.
     */
    private function suspend(ToolLoopService $service, ToolExecutionContext $context, string $name): SuspendedRunState
    {
        try {
            $service->runLoop([['role' => 'user', 'content' => 'do it']], $this->localConfiguration(), $context, null);
        } catch (ToolApprovalRequiredException $e) {
            self::assertSame($name, $e->state->toolCalls()[0]->name);

            return $e->state;
        }

        self::fail('Expected the run to suspend for approval before the write.');
    }

    private function policyAllowing(string $toolName): ToolCallPolicyInterface
    {
        return new class ($toolName) implements ToolCallPolicyInterface {
            public function __construct(private readonly string $toolName) {}

            public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user): ToolPolicyDecision
            {
                return $this->decision();
            }

            public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
            {
                return [$this->toolName];
            }

            public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
            {
                return [$this->decision()];
            }

            private function decision(): ToolPolicyDecision
            {
                return new ToolPolicyDecision(
                    toolName: $this->toolName,
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
