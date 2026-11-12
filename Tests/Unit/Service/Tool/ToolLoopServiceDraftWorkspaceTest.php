<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use ArrayObject;
use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
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
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Rule\RequireDraftWorkspaceRule;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicy;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixtures\Process\ProcessPinProbeStub;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryGovernanceEventRepository;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FlippingInvocationRule;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RefusingWriteTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\SelectiveInvocationRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A configuration that requires a draft workspace refuses a write outside one
 * before an approval card is shown, again right before the call runs, and
 * again when a suspended run resumes (ADR-293).
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(ToolInvocationPolicy::class)]
#[CoversClass(RequireDraftWorkspaceRule::class)]
final class ToolLoopServiceDraftWorkspaceTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    private bool $processRun = false;

    /** @var ArrayObject<int, string> */
    private ArrayObject $writes;

    private InMemoryGovernanceEventRepository $governance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes     = new ArrayObject();
        $this->governance = new InMemoryGovernanceEventRepository();
    }

    #[Test]
    public function aWriteInTheLiveWorkspaceIsRefusedWithoutAnApprovalCard(): void
    {
        $result = $this->service([$this->response('', [new ToolCall('c1', 'plain_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(true), $this->context(0, 0), null);

        self::assertSame('done', $result->finalContent);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringContainsString('rule: draft_workspace_required, reason: live_workspace', (string) $result->trace[0]->result);
        self::assertStringContainsString('requires a draft workspace', (string) $result->trace[0]->result);
        self::assertStringContainsString('send the request again', (string) $result->trace[0]->result);
        self::assertSame([], $this->writes->getArrayCopy());
        self::assertSame(['live_workspace'], $this->denialReasons());
    }

    #[Test]
    public function theRefusalIsInTheLanguageOfTheActingUser(): void
    {
        $user            = $this->userIn('de');
        $user->workspace = 0;

        $context = new ToolExecutionContext(AiActorContext::backendUser(1, true, [], [], 0), $user, new AgentRunReference(1, 'run'));

        $result = $this->service([$this->response('', [new ToolCall('c1', 'plain_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(true), $context, null);

        self::assertStringContainsString('Entwurfs-Arbeitsumgebung', (string) $result->trace[0]->result);
    }

    #[Test]
    public function aWriteInADraftWorkspaceStillSuspendsAndWritesOnApproval(): void
    {
        $state = $this->suspendOn([new ToolCall('c1', 'plain_write', ['id' => 'a'])], $this->configuration(true), $this->context(3, 3));

        self::assertSame(['c1'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));

        $this->service([$this->response('applied')])->resume($state, true, $this->configuration(true), $this->context(3, 3));

        self::assertSame(['a'], $this->writes->getArrayCopy());
        self::assertSame([], $this->governance->recorded);
    }

    #[Test]
    public function anAdministratorInTheLiveWorkspaceIsRefusedToo(): void
    {
        $result = $this->service([$this->response('', [new ToolCall('c1', 'plain_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(true), $this->context(0, 0, admin: true), null);

        self::assertStringContainsString('reason: live_workspace', (string) $result->trace[0]->result);
        self::assertSame([], $this->writes->getArrayCopy());
    }

    #[Test]
    public function aRunThatRecordedNoWorkspaceIsRefused(): void
    {
        $result = $this->service([$this->response('', [new ToolCall('c1', 'plain_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(true), $this->context(null, 0), null);

        self::assertStringContainsString('reason: live_workspace', (string) $result->trace[0]->result);
    }

    #[Test]
    public function aRunWhoseUserLostTheDraftWorkspaceIsRefusedWithItsOwnReason(): void
    {
        $result = $this->service([$this->response('', [new ToolCall('c1', 'plain_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(true), $this->context(3, null), null);

        self::assertStringContainsString('rule: draft_workspace_required, reason: workspace_unavailable', (string) $result->trace[0]->result);
        self::assertStringContainsString('no longer available', (string) $result->trace[0]->result);
        self::assertSame([], $this->writes->getArrayCopy());
    }

    #[Test]
    public function withTheSettingOffALiveWriteSuspendsAsBefore(): void
    {
        $state = $this->suspendOn([new ToolCall('c1', 'plain_write', ['id' => 'a'])], $this->configuration(false), $this->context(0, 0));

        self::assertSame(['c1'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertSame([], $this->governance->recorded);
    }

    #[Test]
    public function aReadIsNotAffectedInTheLiveWorkspace(): void
    {
        $result = $this->service([$this->response('', [new ToolCall('c1', 'read_page', [])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(true), $this->context(0, 0), null);

        self::assertSame('PAGE', $result->trace[0]->result);
        self::assertFalse($result->trace[0]->isError);
        self::assertSame([], $this->governance->recorded);
    }

    /**
     * The card was shown while the setting was off; the setting is on when the
     * editor approves. The call is refused when it runs, and recorded once.
     */
    #[Test]
    public function aResumedRunIsCheckedAgainBeforeItsCallRuns(): void
    {
        $configuration = $this->configuration(false);
        $state         = $this->suspendOn([new ToolCall('c1', 'plain_write', ['id' => 'a'])], $configuration, $this->context(0, 0));
        $configuration->setRequireDraftWorkspace(true);

        $captured = [];
        $this->service([$this->response('applied')], $captured)->resume($state, true, $configuration, $this->context(0, 0));

        self::assertSame([], $this->writes->getArrayCopy(), 'an approved call wrote although the configuration now requires a draft workspace');
        self::assertStringContainsString('reason: live_workspace', $this->toolResults($captured[0])[0] ?? '');
        self::assertSame(['live_workspace'], $this->denialReasons());
    }

    #[Test]
    public function aGuidedProcessTurnDoesNotProposeAWriteTheRuleRefuses(): void
    {
        $this->processRun = true;

        $result = $this->service([$this->response('', [new ToolCall('c1', 'plain_write', ['id' => 'a'])]), $this->response('done')])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(true), $this->context(0, 0), null);

        self::assertSame('done', $result->finalContent);
        self::assertStringContainsString('reason: live_workspace', (string) $result->trace[0]->result);
        self::assertSame(['live_workspace'], $this->denialReasons());
    }

    /**
     * Beside a call that waits, a refused call stays on the card and says so;
     * approving the turn writes the other call only, and the refusal is
     * recorded once, when the turn is decided.
     */
    #[Test]
    public function inATurnThatSuspendsTheRefusedCallsCardSaysSoAndOnlyTheOtherCallWrites(): void
    {
        $rule  = new SelectiveInvocationRule('other_write', 'Refused on purpose.');
        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'other_write', ['id' => 'b']),
        ], $this->configuration(false), $this->context(0, 0), [$rule]);

        self::assertSame(['Refused on purpose.'], $state->callPreviews[1]['lines'] ?? null);
        self::assertTrue($state->callPreviews[1]['failed'] ?? false);
        self::assertSame(['would write'], $state->callPreviews[0]['lines'] ?? null);
        self::assertSame([], $this->denialReasons(), 'asking before the card recorded a refusal');

        $captured = [];
        $this->service([$this->response('applied')], $captured, [$rule])->resume($state, true, $this->configuration(false), $this->context(0, 0));

        self::assertSame(['a'], $this->writes->getArrayCopy());
        self::assertSame('WROTE', $this->toolResults($captured[0])[0] ?? null);
        self::assertStringContainsString('Refused on purpose.', $this->toolResults($captured[0])[1] ?? '');
        self::assertSame(
            [GovernanceDecision::INVOCATION_DENIED->value],
            array_map(static fn (GovernanceEvent $event): string => $event->decision, $this->governance->recorded),
        );
    }

    /**
     * The approver read an explanation on the card, not a preview of what the
     * call would do. If the rule allows the call by the time the turn is
     * approved, it still does not run.
     */
    #[Test]
    public function aCallTheCardShowedRefusedNeverRunsEvenWhenTheRuleNowAllows(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'other_write', ['id' => 'b']),
        ], $this->configuration(false), $this->context(0, 0), [new SelectiveInvocationRule('other_write', 'Refused on purpose.')]);

        $captured = [];
        $this->service([$this->response('applied')], $captured, [new SelectiveInvocationRule('nothing', 'unused')])
            ->resume($state, true, $this->configuration(false), $this->context(0, 0));

        self::assertSame(['a'], $this->writes->getArrayCopy(), 'a call the card showed refused ran once the rule allowed it');
        self::assertStringContainsString('denied before approval', $this->toolResults($captured[0])[1] ?? '');
        self::assertSame(['refused_on_card'], $this->denialReasons());
    }

    /**
     * A refusal the stored state carries survives the round trip that every
     * suspended run makes through its encoded form.
     */
    #[Test]
    public function theRefusalOnTheCardSurvivesTheStoredForm(): void
    {
        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'other_write', ['id' => 'b']),
        ], $this->configuration(false), $this->context(0, 0), [new SelectiveInvocationRule('other_write', 'Refused on purpose.')]);

        $stored = SuspendedRunState::fromArray($state->toArray());

        self::assertTrue($stored->callPreviews[1]['refused'] ?? false);
        self::assertArrayNotHasKey('refused', $stored->callPreviews[0]);
    }

    /**
     * A rule that fails says nothing about the call. The call waits for
     * approval like any other write, and is asked again when it runs.
     */
    #[Test]
    public function aRuleThatFailsDoesNotTakeTheApprovalAway(): void
    {
        $rule  = new FlippingInvocationRule(0, throws: true);
        $state = $this->suspendOn([new ToolCall('c1', 'plain_write', ['id' => 'a'])], $this->configuration(false), $this->context(0, 0), [$rule]);

        self::assertSame(['c1'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertArrayNotHasKey('refused', $state->callPreviews[0] ?? []);

        $captured = [];
        $this->service([$this->response('applied')], $captured, [$rule])->resume($state, true, $this->configuration(false), $this->context(0, 0));

        self::assertSame([], $this->writes->getArrayCopy());
        self::assertStringContainsString('reason: rule_failed', $this->toolResults($captured[0])[0] ?? '');
    }

    /**
     * No card was shown, because the rule refused the call. If the rule allows
     * it when it runs, it is refused for the approval it skipped.
     */
    #[Test]
    public function aCallRefusedBeforeTheCardIsNeverRunWhenTheRuleAllowsItAtTheDoor(): void
    {
        $result = $this->service([$this->response('', [new ToolCall('c1', 'plain_write', ['id' => 'a'])]), $this->response('done')], rules: [new FlippingInvocationRule(1)])
            ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(false), $this->context(0, 0), null);

        self::assertSame('done', $result->finalContent);
        self::assertSame([], $this->writes->getArrayCopy(), 'a call that skipped the approval ran');
        self::assertTrue($result->trace[0]->isError);
        self::assertStringContainsString('requires approval that was not given', (string) $result->trace[0]->result);
    }

    /**
     * Beside a proposal of a guided process, a call the rule refuses is
     * settled at once and never runs, with the refusal recorded once.
     */
    #[Test]
    public function aProcessTurnSettlesARefusedWriteBesideItsProposal(): void
    {
        $this->processRun = true;

        $state = $this->suspendOn([
            new ToolCall('c1', 'plain_write', ['id' => 'a']),
            new ToolCall('c2', 'other_write', ['id' => 'b']),
        ], $this->configuration(false), $this->context(0, 0), [new SelectiveInvocationRule('other_write', 'Refused on purpose.')]);

        self::assertSame(['c1'], array_map(static fn (ToolCall $c): string => $c->id, $state->toolCalls()));
        self::assertStringContainsString('Refused on purpose.', $this->toolResults($state->messages)[0] ?? '');
        self::assertSame([], $this->writes->getArrayCopy());
        self::assertSame(['refused_on_purpose'], $this->denialReasons());
    }

    /**
     * A rule that did not opt in is asked immediately before the call runs and
     * not before: before the card the history of the turn is not what it will
     * be when the call runs.
     */
    #[Test]
    public function aRuleThatDidNotOptInIsNotAskedBeforeTheCard(): void
    {
        $asked = new ArrayObject();
        $rule  = new class ($asked) implements ToolInvocationRuleInterface {
            /**
             * @param ArrayObject<int, string> $asked
             */
            public function __construct(private ArrayObject $asked) {}

            public function identifier(): string
            {
                return 'late_rule';
            }

            public function requiresCompleteHistory(): bool
            {
                return false;
            }

            public function decide(ToolInvocationContext $context): ToolInvocationDecision
            {
                $this->asked[] = $context->toolName;

                return ToolInvocationDecision::allow();
            }
        };

        $state = $this->suspendOn([new ToolCall('c1', 'plain_write', ['id' => 'a'])], $this->configuration(false), $this->context(0, 0), [$rule]);
        self::assertSame([], $asked->getArrayCopy(), 'a rule that did not opt in was asked before the card');

        $this->service([$this->response('applied')], rules: [$rule])->resume($state, true, $this->configuration(false), $this->context(0, 0));
        self::assertSame(['plain_write'], $asked->getArrayCopy());
    }

    /**
     * A rule that opted in is asked before the card and again when the call
     * runs, and the second answer is the one that counts.
     */
    #[Test]
    public function aRuleThatOptedInIsAskedBeforeTheCardAndAgainWhenTheCallRuns(): void
    {
        $rule          = new SelectiveInvocationRule('nothing', 'unused');
        $state         = $this->suspendOn([new ToolCall('c1', 'plain_write', ['id' => 'a'])], $this->configuration(false), $this->context(0, 0), [$rule]);
        $beforeTheCard = count($rule->asked);
        self::assertGreaterThan(0, $beforeTheCard, 'a rule that opted in was not asked before the card');

        $this->service([$this->response('applied')], rules: [$rule])->resume($state, true, $this->configuration(false), $this->context(0, 0));
        self::assertCount($beforeTheCard + 1, $rule->asked, 'the rule was not asked again when the approved call ran');
    }

    /**
     * @param list<ToolCall>                    $turn
     * @param list<ToolInvocationRuleInterface> $rules
     */
    private function suspendOn(array $turn, LlmConfiguration $configuration, ToolExecutionContext $context, ?array $rules = null): SuspendedRunState
    {
        try {
            $this->service([$this->response('', $turn)], rules: $rules)
                ->runLoop([['role' => 'user', 'content' => 'go']], $configuration, $context, null);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }

        self::fail('Expected the run to suspend for approval.');
    }

    /**
     * @return list<string>
     */
    private function denialReasons(): array
    {
        return array_values(array_map(
            static fn (GovernanceEvent $event): string => $event->reason,
            array_filter($this->governance->recorded, static fn (GovernanceEvent $event): bool => $event->decision === GovernanceDecision::INVOCATION_DENIED->value),
        ));
    }

    /**
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

    /**
     * @param int|null $workspace     the workspace the run recorded when it started, null where it did not
     * @param int|null $userWorkspace the workspace the acting user is set up in now, null for no acting user
     */
    private function context(?int $workspace, ?int $userWorkspace, bool $admin = false): ToolExecutionContext
    {
        $user = null;
        if ($userWorkspace !== null) {
            $user            = $this->userIn('en');
            $user->user      = ['uid' => 1, 'admin' => $admin ? 1 : 0, 'lang' => 'en'];
            $user->workspace = $userWorkspace;
        }

        return new ToolExecutionContext(AiActorContext::backendUser(1, $admin, [], [], $workspace), $user, new AgentRunReference(1, 'run'));
    }

    /**
     * @param list<CompletionResponse>               $queue
     * @param list<array<int, mixed>>                $captured
     * @param list<ToolInvocationRuleInterface>|null $rules    the rules of the installation; the draft workspace rule alone when null
     */
    private function service(array $queue, array &$captured = [], ?array $rules = null): ToolLoopService
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (array $messages) use (&$queue, &$captured): CompletionResponse {
                $captured[] = $messages;
                $next       = array_shift($queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1799293001);
                }

                return $next;
            },
        );

        $registry = new ToolRegistry([
            new FakeTool('read_page', 'PAGE'),
            new RefusingWriteTool('plain_write', false, $this->writes),
            new RefusingWriteTool('other_write', false, $this->writes),
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
            governanceEvents: $this->governance,
            previewTranslator: $this->englishTranslator(),
            invocationPolicy: new ToolInvocationPolicy($rules ?? [new RequireDraftWorkspaceRule($registry, $this->englishTranslator())]),
            processPinProbe: new ProcessPinProbeStub(everyRun: $this->processRun),
        );
    }

    private function configuration(bool $requireDraftWorkspace): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);
        $configuration->setRequireDraftWorkspace($requireDraftWorkspace);

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
