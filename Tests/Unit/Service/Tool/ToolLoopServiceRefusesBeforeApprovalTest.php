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
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationHistory;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\WorkerWriteEnvironment;
use Netresearch\NrLlm\Tests\Fixtures\Process\ProcessPinProbeStub;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryGovernanceEventRepository;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RefusingWriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

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

    /** How often `flipping_write` refuses before it stops refusing; null while a lifting read decides. */
    private ?int $refusalsLeft = null;

    /** Whether the read `lifting_read` ran, which lifts the refusal of `flipping_write`. */
    private bool $lifted = false;

    private InMemoryGovernanceEventRepository $events;

    /** @var list<ToolInvocationHistory> the history each admitted call saw */
    private array $histories = [];

    private ?string $deniedTool = null;

    private ?WorkerWriteEnvironment $writeEnvironment = null;

    /** @var list<array{bool, bool}> whether a backend user and a language service were there when `flipping_write` was asked */
    private array $environments = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes = new ArrayObject();
        $this->events = new InMemoryGovernanceEventRepository();
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
     * The state a refusal rests on can change between the scan and the
     * settlement; the call skipped the approval, so it is never executed
     * (ADR-328).
     */
    #[Test]
    public function aCallWhoseRefusalIsLiftedAfterTheScanIsNotExecuted(): void
    {
        $this->refusalsLeft = 1;

        $result = $this->service([
            $this->response('', [new ToolCall('c1', 'flipping_write', ['id' => 'a'])]),
            $this->response('', [new ToolCall('c2', 'read_page', [])]),
            $this->response('done'),
        ])->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null);

        self::assertSame([], $this->writes->getArrayCopy(), 'a call that skipped the approval wrote');
        self::assertSame('Error: tool "flipping_write" requires approval that was not given.', $result->trace[0]->result);
        self::assertTrue($result->trace[0]->isError);
        self::assertSame(['write_unapproved/refusalLifted'], $this->governance());
        self::assertSame(['flipping_write:denied'], $this->historySeenBy(1));
    }

    /**
     * An earlier call of the same turn that needs no approval lifts the
     * refusal: the writing call is still not executed.
     */
    #[Test]
    public function aCallWhoseRefusalAnEarlierCallOfTheTurnLiftsIsNotExecuted(): void
    {
        $result = $this->service([
            $this->response('', [new ToolCall('c1', 'lifting_read', []), new ToolCall('c2', 'flipping_write', ['id' => 'a'])]),
            $this->response('done'),
        ])->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null);

        self::assertTrue($this->lifted);
        self::assertSame([], $this->writes->getArrayCopy());
        self::assertStringContainsString('requires approval that was not given', $result->trace[1]->result);
    }

    /**
     * What a refused call leaves behind is what an executed call leaves: its
     * trace step, its outcome in the history, no governance event.
     */
    #[Test]
    public function aRefusedCallLeavesTheRecordsOfAnExecutedCall(): void
    {
        $before = [];
        $trace  = new RunTrace(onBeforeTool: static function (string $name) use (&$before): void {
            $before[] = $name;
        });

        $this->service([
            $this->response('', [new ToolCall('c1', 'refused_write', ['id' => 'a'])]),
            $this->response('', [new ToolCall('c2', 'read_page', [])]),
            $this->response('done'),
        ])->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null, runTrace: $trace);

        self::assertSame(['refused_write', 'read_page'], $before);
        self::assertSame([], $this->governance());
        self::assertSame(['refused_write:failed'], $this->historySeenBy(1));

        $steps = array_values(array_filter($trace->getSteps(), static fn (RunStep $step): bool => $step->kind === RunStep::KIND_TOOL));
        self::assertCount(2, $steps);
        self::assertSame(RefusingWriteTool::REFUSAL, $steps[0]->toolResult);
        self::assertTrue($steps[0]->toolIsError);
    }

    /**
     * The gates come first, as for any call: a call an invocation rule denies
     * is recorded as denied, and the tool's refusal is not its result.
     */
    #[Test]
    public function aRefusedCallPassesTheGatesOfAnyCall(): void
    {
        $this->deniedTool = 'refused_write';

        $result = $this->service([$this->response('', [new ToolCall('c1', 'refused_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null);

        self::assertStringContainsString('invocation denied (rule: test_rule', $result->trace[0]->result);
        self::assertSame(['invocation_denied/test_denied'], $this->governance());
    }

    /**
     * A worker has no language service; the refusal is asked in the
     * environment the call would be executed in, and nothing of it stays.
     */
    #[Test]
    public function theRefusalIsAskedInTheEnvironmentOfTheWrite(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $languages = self::createStub(LanguageServiceFactory::class);
        $languages->method('createFromUserPreferences')->willReturn(self::createStub(LanguageService::class));
        $this->writeEnvironment = new WorkerWriteEnvironment($languages, true);

        $user = self::createStub(BackendUserAuthentication::class);
        $this->service([$this->response('', [new ToolCall('c1', 'flipping_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), new ToolExecutionContext(AiActorContext::anonymous(), $user, new AgentRunReference(1, 'run')), null);

        self::assertNotSame([], $this->environments);
        self::assertSame([[true, true]], array_values(array_unique($this->environments, SORT_REGULAR)));
        self::assertArrayNotHasKey('LANG', $GLOBALS);
    }

    /**
     * Whichever of the two calls comes first, the valid write suspends the
     * turn and the refused one is not settled before the decision.
     */
    #[Test]
    public function aRefusedCallBeforeAValidWriteDoesNotKeepTheTurnFromSuspending(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'refused_write', ['id' => 'b']),
            new ToolCall('c2', 'plain_write', ['id' => 'a']),
        ]);

        self::assertSame(['c1', 'c2'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertTrue($state->callPreviews[0]['refusedByTool'] ?? false);
        self::assertArrayNotHasKey('refusedByTool', $state->callPreviews[1]);
        self::assertSame([], $this->writes->getArrayCopy());
    }

    /**
     * The card showed the refusal, the comparison of the cards still found it,
     * and the tool stops refusing before the calls run: nobody approved what
     * the call would write now.
     */
    #[Test]
    public function aRefusalThatIsLiftedAfterTheCardsWereComparedIsNotExecuted(): void
    {
        $stored = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'flipping_write', ['id' => 'b']),
        ]);
        $state = SuspendedRunState::fromArray($stored->toArray());

        // Asked once by the comparison's preview, and not refused after it.
        $this->refusalsLeft = 1;
        $captured           = [];
        $this->service([$this->response('applied')], $captured)->resume($state, true, $this->configuration(), $this->context());

        self::assertSame(['a'], $this->writes->getArrayCopy());
        self::assertSame(
            ['WROTE', 'Error: tool "flipping_write" requires approval that was not given.'],
            $this->toolResults($captured[0]),
        );
        self::assertSame(['write_unapproved/refusalLifted'], $this->governance());
    }

    /**
     * A card that comes back because it now shows a refusal is marked.
     */
    #[Test]
    public function aCardShownAgainWithARefusalIsMarked(): void
    {
        $this->refuses = false;
        $state         = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'refused_write', ['id' => 'b']),
        ]);
        self::assertArrayNotHasKey('refusedByTool', $state->callPreviews[1]);

        $this->refuses = true;

        try {
            $this->service([])->resume($state, true, $this->configuration(), $this->context());
            self::fail('Expected the stale card to pause the run again.');
        } catch (ToolApprovalRequiredException $e) {
            self::assertTrue($e->state->callPreviews[1]['refusedByTool'] ?? false);
            self::assertArrayNotHasKey('refusedByTool', $e->state->callPreviews[0]);
        }
    }

    /**
     * A card shown again says anew whether its tool refuses the call: a write
     * the second card describes and the approver approves is executed.
     */
    #[Test]
    public function aCallTheSecondCardDescribesIsExecutedOnceItIsApproved(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'refused_write', ['id' => 'b']),
        ]);

        $this->refuses = false;

        try {
            $this->service([])->resume($state, true, $this->configuration(), $this->context());
            self::fail('Expected the stale card to pause the run again.');
        } catch (ToolApprovalRequiredException $e) {
            $state = $e->state;
        }

        self::assertArrayNotHasKey('refusedByTool', $state->callPreviews[1]);
        self::assertSame(['would write'], $state->callPreviews[1]['lines'] ?? null);
        $this->service([$this->response('applied')])->resume($state, true, $this->configuration(), $this->context());

        self::assertSame(['a', 'b'], $this->writes->getArrayCopy());
    }

    /**
     * The refusal the card showed is a tool result like any other: bounded
     * before the model reads it.
     */
    #[Test]
    public function aRefusalTheCardShowedIsBoundedBeforeTheModelReadsIt(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'long_refusal', ['id' => 'b']),
        ]);
        $captured = [];
        $this->service([$this->response('applied')], $captured)->resume($state, true, $this->configuration(), $this->context());

        $result = $this->toolResults($captured[0])[1] ?? '';
        self::assertStringStartsWith('rrr', $result);
        self::assertLessThanOrEqual(50000, strlen($result));
        self::assertStringContainsString('tool result truncated', $result);
    }

    /**
     * What an approved call costs its tool, whose refusal can be a whole plan:
     * the scan, the card and its mark; the comparison, the refusal on the
     * resume and the write. A card that is not shown again gets no new mark.
     */
    #[Test]
    public function anApprovedCallAsksItsToolSixTimes(): void
    {
        $this->refusalsLeft = 0;

        $state = $this->suspendOn([new ToolCall('c1', 'flipping_write', ['id' => 'a'])]);
        self::assertCount(3, $this->environments);

        $this->service([$this->response('applied')])->resume($state, true, $this->configuration(), $this->context());

        self::assertSame(['a'], $this->writes->getArrayCopy());
        self::assertCount(6, $this->environments);
    }

    /**
     * Beside a proposal the same holds at every moment the refusal can be
     * lifted: after the proposal was chosen, and after the call was found
     * refused.
     */
    #[Test]
    #[DataProvider('liftsBesideAProposal')]
    public function besideAProposalACallWhoseRefusalIsLiftedIsNotExecuted(int $refusals, string $expected): void
    {
        $this->processRun   = true;
        $this->refusalsLeft = $refusals;

        $state = $this->suspendOn([
            new ToolCall('c1', 'flipping_write', ['id' => 'b']),
            new ToolCall('c2', 'plain_write', ['id' => 'a']),
        ]);

        self::assertSame(['c2'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertStringContainsString($expected, $this->toolResults($state->messages)[0] ?? '');
        self::assertSame([], $this->writes->getArrayCopy());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function liftsBesideAProposal(): array
    {
        return [
            'after the proposal was chosen'        => [1, 'one approval per turn in a process'],
            'after the call was found refused'     => [2, 'requires approval that was not given'],
            'after the call was settled'           => [3, RefusingWriteTool::REFUSAL],
            'not at all, and it keeps its refusal' => [9, RefusingWriteTool::REFUSAL],
        ];
    }

    /**
     * An answer to a question approves nothing: a call that was refused when
     * the answer arrived and is refused no longer is not executed.
     */
    #[Test]
    #[DataProvider('liftsBesideAnAnswer')]
    public function besideAnAnswerACallWhoseRefusalIsLiftedIsNotExecuted(int $refusals, string $expected): void
    {
        try {
            $this->service([$this->response('', [new ToolCall('c1', 'ask_user', []), new ToolCall('c2', 'flipping_write', ['id' => 'b'])])])
                ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $this->context(), null);
            self::fail('Expected the run to suspend for input.');
        } catch (ToolInputRequiredException $e) {
            $state = $e->state;
        }

        $this->refusalsLeft = $refusals;
        $captured           = [];
        $this->service([$this->response('', [new ToolCall('c3', 'read_page', [])]), $this->response('ok')], $captured)
            ->resumeWithInput($state, ['city' => 'Berlin'], $this->configuration(), $this->context());

        self::assertSame($expected, $this->toolResults($captured[0])[1] ?? null);
        self::assertSame([], $this->writes->getArrayCopy());
        // One entry per call: the settled call is not recorded twice.
        self::assertCount(2, $this->historySeenBy(count($this->histories) - 1));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function liftsBesideAnAnswer(): array
    {
        $unapproved = 'Error: tool "flipping_write" requires approval that was not given.';

        return [
            'before it was asked'              => [0, $unapproved],
            'after the call was found refused' => [1, $unapproved],
            'after the call was settled'       => [2, RefusingWriteTool::REFUSAL],
            'not at all'                       => [9, RefusingWriteTool::REFUSAL],
        ];
    }

    /**
     * @return list<string> decision/reason of every recorded governance event
     */
    private function governance(): array
    {
        return array_map(static fn (GovernanceEvent $event): string => $event->decision . '/' . $event->reason, $this->events->recorded);
    }

    /**
     * @return list<string> tool:outcome of the history the nth admitted call saw
     */
    private function historySeenBy(int $index): array
    {
        $history = $this->histories[$index] ?? null;
        self::assertInstanceOf(ToolInvocationHistory::class, $history);

        return array_map(static fn (array $entry): string => $entry['tool'] . ':' . $entry['outcome'], $history->entries);
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
            new RefusingWriteTool('flipping_write', true, $this->writes, when: $this->flippingWriteRefuses(...)),
            new RefusingWriteTool('long_refusal', true, $this->writes, refusal: str_repeat('r', 60000)),
            $this->liftingRead(),
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
            governanceEvents: $this->events,
            invocationPolicy: $this->invocationPolicy(),
            writeEnvironment: $this->writeEnvironment,
        );
    }

    private function flippingWriteRefuses(): bool
    {
        $this->environments[] = [
            ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication,
            ($GLOBALS['LANG'] ?? null) instanceof LanguageService,
        ];

        return $this->refusalsLeft === null ? !$this->lifted : $this->refusalsLeft-- > 0;
    }

    /**
     * A read that needs no approval and changes what `flipping_write` refuses on.
     */
    private function liftingRead(): ToolInterface
    {
        $tool = self::createStub(ToolInterface::class);
        $tool->method('getSpec')->willReturn(ToolSpec::function('lifting_read', 'lifts', ['type' => 'object', 'properties' => []]));
        $tool->method('isEnabledByDefault')->willReturn(true);
        $tool->method('requiresAdmin')->willReturn(false);
        $tool->method('getGroup')->willReturn('test');
        $tool->method('execute')->willReturnCallback(function (): ToolResult {
            $this->lifted = true;

            return ToolResult::text('LIFTED');
        });

        return $tool;
    }

    private function invocationPolicy(): ToolInvocationPolicyInterface
    {
        $policy = self::createStub(ToolInvocationPolicyInterface::class);
        $policy->method('resolveTarget')->willReturn(null);
        $policy->method('decide')->willReturnCallback(function (ToolInvocationContext $context): ToolInvocationDecision {
            $this->histories[] = $context->history;

            return $context->toolName === $this->deniedTool
                ? new ToolInvocationDecision(false, 'test_denied', 'test_rule')
                : new ToolInvocationDecision(true);
        });

        return $policy;
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
