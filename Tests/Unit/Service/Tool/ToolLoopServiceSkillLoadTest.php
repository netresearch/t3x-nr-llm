<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextBudgetBreakdown;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\Context\SectionAdmissionInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillLoadService;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\LoadSkillTool;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillLoadLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The agent loop's side of loading a skill (ADR-214 items 4, 5 and 7): the
 * load tool is offered only with a non-empty catalogue, a load writes the
 * section into the head system message before the next call and answers
 * without the body, a repeated load appends nothing, a section that does not
 * fit is refused, and a load never widens the offered tools.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceSkillLoadTest extends TestCase
{
    private const CONFIGURATION_UID = 9;

    private const SKILL_UID = 7;

    private const BODY = 'Write the answer in the house style.';

    private const CONFIG_SYSTEM_PROMPT = 'You are the configured assistant.';

    private ?InMemorySkillApprovalRepository $approvals = null;

    /** @var list<array{messages: list<ChatMessage|array<string, mixed>>, specs: list<ToolSpec>}> */
    private array $sends = [];

    #[Test]
    public function withoutAnOnDemandAttachmentTheLoadToolIsNotOffered(): void
    {
        $this->runLoad([], attachOnDemand: false);

        self::assertNotContains(LoadSkillTool::NAME, $this->offeredNames(0));
        self::assertContains('noop', $this->offeredNames(0));
    }

    #[Test]
    public function anUnapprovedOnDemandSkillLeavesTheCatalogueEmptyAndTheToolUnoffered(): void
    {
        $this->runLoad([], approve: false);

        self::assertNotContains(LoadSkillTool::NAME, $this->offeredNames(0));
    }

    #[Test]
    public function anApprovedOnDemandSkillIsOfferedThroughTheLoadToolsCatalogue(): void
    {
        $this->runLoad([]);

        self::assertContains(LoadSkillTool::NAME, $this->offeredNames(0));
        $spec = $this->spec(0, LoadSkillTool::NAME);
        self::assertStringContainsString('7: House style — How we write.', $spec->description);
        self::assertStringNotContainsString(self::BODY, $spec->description);
        self::assertStringNotContainsString(self::BODY, $this->systemText(0), 'an on-demand skill is not composed at run start');
    }

    #[Test]
    public function aLoadWritesTheSectionIntoTheSystemMessageAndAnswersWithoutTheBody(): void
    {
        $this->runLoad([[$this->loadCall('c1')]]);

        self::assertCount(2, $this->sends);
        self::assertStringStartsWith(self::CONFIG_SYSTEM_PROMPT . "\n\n## Approved skills", $this->systemText(1));
        self::assertStringContainsString(self::BODY, $this->systemText(1));
        $result = $this->toolResult(1, 'c1');
        self::assertStringContainsString('is loaded', $result);
        self::assertStringNotContainsString(self::BODY, $result);
    }

    /**
     * A turn that holds a load next to an approval-bound write suspends
     * before either runs; the resume loads through the same seam, so the
     * section joins the system message and its pin is the run's.
     */
    #[Test]
    public function aLoadInASuspendedTurnRunsOnResume(): void
    {
        $turns = [[$this->loadCall('c1'), new ToolCall('c2', 'write', [])]];
        [$service, $configuration] = $this->service($turns);

        try {
            $service->runLoop([ChatMessage::user('write the release note')], $configuration, ToolExecutionContext::none(), null, null, 5);
            self::fail('The write did not suspend the run.');
        } catch (ToolApprovalRequiredException $e) {
            $state = $e->state;
        }

        $service->resume($state, true, $configuration, ToolExecutionContext::none());

        $last = count($this->sends) - 1;
        self::assertStringContainsString(self::BODY, $this->systemText($last));
        self::assertStringContainsString('is loaded', $this->toolResult($last, 'c1'));
    }

    /**
     * The same holds for a turn that suspended for typed input: the load
     * runs through the seam when the input arrives.
     */
    #[Test]
    public function aLoadInATurnSuspendedForInputRunsOnResumeWithInput(): void
    {
        $turns = [[$this->loadCall('c1'), new ToolCall('c2', 'ask_user', [])]];
        [$service, $configuration] = $this->service($turns);

        try {
            $service->runLoop([ChatMessage::user('write the release note')], $configuration, ToolExecutionContext::none(), null, null, 5);
            self::fail('The input tool did not suspend the run.');
        } catch (ToolInputRequiredException $e) {
            $state = $e->state;
        }

        $service->resumeWithInput($state, ['city' => 'Berlin'], $configuration, ToolExecutionContext::none());

        $last = count($this->sends) - 1;
        self::assertStringContainsString(self::BODY, $this->systemText($last));
        self::assertStringContainsString('is loaded', $this->toolResult($last, 'c1'));
    }

    /**
     * A declined turn runs none of its calls. A load in it is told the turn
     * was declined, not that a human declined the load, and nothing is
     * appended.
     */
    #[Test]
    public function aLoadInADeclinedTurnIsNotRunAndSaysWhy(): void
    {
        $turns = [[$this->loadCall('c1'), new ToolCall('c2', 'write', [])]];
        [$service, $configuration] = $this->service($turns);

        try {
            $service->runLoop([ChatMessage::user('write the release note')], $configuration, ToolExecutionContext::none(), null, null, 5);
            self::fail('The write did not suspend the run.');
        } catch (ToolApprovalRequiredException $e) {
            $state = $e->state;
        }

        $service->resume($state, false, $configuration, ToolExecutionContext::none());

        $last = count($this->sends) - 1;
        self::assertStringNotContainsString(self::BODY, $this->systemText($last));
        self::assertStringContainsString('the turn it was requested in was declined', $this->toolResult($last, 'c1'));
        self::assertStringNotContainsString('the turn it was requested in was declined', $this->toolResult($last, 'c2'), 'the write keeps its own answer');
    }

    /**
     * Both resume paths start the window's measurement afresh before a load
     * is admitted, so another run's calibration does not decide it.
     */
    #[Test]
    public function aResumeStartsTheWindowsMeasurementAfresh(): void
    {
        $turns = [[$this->loadCall('c1'), new ToolCall('c2', 'write', [])]];
        [$service, $configuration, $window] = $this->service($turns, admits: true);
        self::assertNotNull($window);

        try {
            $service->runLoop([ChatMessage::user('write the release note')], $configuration, ToolExecutionContext::none(), null, null, 5);
            self::fail('The write did not suspend the run.');
        } catch (ToolApprovalRequiredException $e) {
            $state = $e->state;
        }

        $before = $window->begun;
        $service->resume($state, true, $configuration, ToolExecutionContext::none());

        self::assertSame($before + 1, $window->begun);

        $turns = [[$this->loadCall('c1'), new ToolCall('c2', 'ask_user', [])]];
        [$service, $configuration, $window] = $this->service($turns, admits: true);
        self::assertNotNull($window);

        try {
            $service->runLoop([ChatMessage::user('write the release note')], $configuration, ToolExecutionContext::none(), null, null, 5);
            self::fail('The input tool did not suspend the run.');
        } catch (ToolInputRequiredException $e) {
            $state = $e->state;
        }

        $before = $window->begun;
        $service->resumeWithInput($state, ['city' => 'Berlin'], $configuration, ToolExecutionContext::none());

        self::assertSame($before + 1, $window->begun);
    }

    /**
     * A resumed segment whose catalogue is empty does not offer the load
     * tool, so a load the suspended turn holds is refused as not permitted.
     */
    #[Test]
    public function aResumeWithAnEmptyCatalogueDoesNotOfferTheLoadTool(): void
    {
        $turns = [[$this->loadCall('c1'), new ToolCall('c2', 'write', [])]];
        [$service, $configuration] = $this->service($turns);

        try {
            $service->runLoop([ChatMessage::user('write the release note')], $configuration, ToolExecutionContext::none(), null, null, 5);
            self::fail('The write did not suspend the run.');
        } catch (ToolApprovalRequiredException $e) {
            $state = $e->state;
        }

        $digest = $this->skill(self::SKILL_UID, 'style', null)->getVersionDigest();
        $this->approvals?->revoke(self::SKILL_UID, $digest, 1);
        $service->resume($state, true, $configuration, ToolExecutionContext::none());

        $last = count($this->sends) - 1;
        self::assertStringContainsString('no longer permitted', $this->toolResult($last, 'c1'));
        self::assertNotContains(LoadSkillTool::NAME, $this->offeredNames($last));
    }

    /**
     * A window manager that cannot admit a section refuses the load instead
     * of skipping the check (item 7).
     */
    #[Test]
    public function aWindowWithoutAdmissionRefusesTheLoad(): void
    {
        $this->runLoad([[$this->loadCall('c1')]], windowWithoutAdmission: true);

        self::assertStringNotContainsString(self::BODY, $this->systemText(1));
        self::assertStringContainsString('cannot be checked', $this->toolResult(1, 'c1'));
    }

    /**
     * A system message given as a part list is not rebuilt around a
     * section: the load is refused and the message left as it is.
     */
    #[Test]
    public function aPartListSystemMessageRefusesTheLoad(): void
    {
        $partList = ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Chat identity.']]];

        $this->runLoad([[$this->loadCall('c1')]], firstMessage: $partList);

        self::assertSame($partList, $this->sends[1]['messages'][0]);
        self::assertStringContainsString('cannot be extended', $this->toolResult(1, 'c1'));
    }

    /**
     * A loaded section is pinned like a composed one (ADR-214 item 6): a run
     * that suspends after the load stores its pin, so the resume re-checks it.
     */
    #[Test]
    public function aSuspensionAfterALoadStoresTheLoadedPin(): void
    {
        try {
            $this->runLoad([[$this->loadCall('c1')], [new ToolCall('c2', 'write', [])]]);
            self::fail('The write did not suspend the run.');
        } catch (ToolApprovalRequiredException $e) {
            self::assertCount(1, $e->state->skillPins);
            self::assertSame(self::SKILL_UID, $e->state->skillPins[0]->skillUid);
        }
    }

    #[Test]
    public function aRefusedLoadStoresNoPin(): void
    {
        try {
            $this->runLoad([[$this->loadCall('c1')], [new ToolCall('c2', 'write', [])]], admits: false);
            self::fail('The write did not suspend the run.');
        } catch (ToolApprovalRequiredException $e) {
            self::assertSame([], $e->state->skillPins);
        }
    }

    #[Test]
    public function aSecondLoadOfTheSameSkillAppendsNothing(): void
    {
        $this->runLoad([[$this->loadCall('c1')], [$this->loadCall('c2')]]);

        self::assertCount(3, $this->sends);
        self::assertSame(1, substr_count($this->systemText(2), self::BODY));
        self::assertStringContainsString('already loaded', $this->toolResult(2, 'c2'));
    }

    #[Test]
    public function aSectionThatDoesNotFitTheWindowIsRefusedAndNotAppended(): void
    {
        $this->runLoad([[$this->loadCall('c1')]], admits: false);

        self::assertStringNotContainsString(self::BODY, $this->systemText(1));
        self::assertStringContainsString('does not fit the context window', $this->toolResult(1, 'c1'));
    }

    #[Test]
    public function aSectionThatFitsTheWindowIsAppended(): void
    {
        $this->runLoad([[$this->loadCall('c1')]], admits: true);

        self::assertStringContainsString(self::BODY, $this->systemText(1));
    }

    #[Test]
    public function aSkillDeclaringNoToolsStillLeavesTheLoadToolOffered(): void
    {
        $this->runLoad([], alwaysAllowedTools: '[]');

        self::assertSame([LoadSkillTool::NAME], $this->offeredNames(0), 'the declaration blocks every other tool, not loading');
    }

    #[Test]
    public function aGloballyDisabledLoadToolIsNotOffered(): void
    {
        $this->runLoad([], enabled: ['noop']);

        self::assertNotContains(LoadSkillTool::NAME, $this->offeredNames(0));
    }

    /**
     * Attachment grants tools, a load grants nothing (ADR-214 item 5): the
     * on-demand skill's declaration is in the run's union from the start,
     * and the offered set after the load is the set before it.
     */
    #[Test]
    public function attachmentGrantsTheToolsAndALoadChangesNothing(): void
    {
        $this->runLoad([[$this->loadCall('c1')]], alwaysAllowedTools: '["noop"]', onDemandAllowedTools: '["secret"]');

        self::assertContains('secret', $this->offeredNames(0), 'the on-demand attachment grants its tools before any load');
        self::assertSame($this->offeredNames(0), $this->offeredNames(1));
    }

    #[Test]
    public function withoutTheOnDemandAttachmentItsToolsAreNotGranted(): void
    {
        $this->runLoad([], attachOnDemand: false, alwaysAllowedTools: '["noop"]', onDemandAllowedTools: '["secret"]');

        self::assertNotContains('secret', $this->offeredNames(0));
    }

    /**
     * @param list<list<ToolCall>>      $turns        the tool calls of each model turn before the final answer
     * @param list<string>|null         $enabled      the globally enabled tools; null = every registered tool
     * @param bool|null                 $admits       the admission answer of a context window that admits; null = no window
     * @param array<string, mixed>|null $firstMessage a message the caller sends before the user turn
     */
    private function runLoad(
        array $turns,
        bool $attachOnDemand = true,
        bool $approve = true,
        ?array $enabled = null,
        ?bool $admits = null,
        ?string $alwaysAllowedTools = null,
        ?string $onDemandAllowedTools = null,
        bool $windowWithoutAdmission = false,
        ?array $firstMessage = null,
    ): void {
        [$service, $configuration] = $this->service($turns, $attachOnDemand, $approve, $enabled, $admits, $alwaysAllowedTools, $onDemandAllowedTools, $windowWithoutAdmission);

        $messages = [];
        if ($firstMessage !== null) {
            $messages[] = $firstMessage;
        }

        $messages[] = ChatMessage::user('write the release note');
        $service->runLoop($messages, $configuration, ToolExecutionContext::none(), null, null, 5);

        self::assertNotSame([], $this->sends, 'The loop did not reach the provider call.');
    }

    /**
     * @param list<list<ToolCall>> $turns
     * @param list<string>|null    $enabled
     *
     * @return array{0: ToolLoopService, 1: LlmConfiguration, 2: (ContextWindowManagerInterface&SectionAdmissionInterface&object{begun: int})|null}
     */
    private function service(
        array &$turns,
        bool $attachOnDemand = true,
        bool $approve = true,
        ?array $enabled = null,
        ?bool $admits = null,
        ?string $alwaysAllowedTools = null,
        ?string $onDemandAllowedTools = null,
        bool $windowWithoutAdmission = false,
    ): array {
        $this->sends = [];
        $manager     = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (array $messages, array $specs) use (&$turns): CompletionResponse {
                /** @var list<ChatMessage|array<string, mixed>> $messages */
                /** @var list<ToolSpec> $specs */
                $this->sends[] = ['messages' => $messages, 'specs' => $specs];
                $calls         = array_shift($turns);

                return new CompletionResponse($calls === null ? 'done' : '', 'test-model', UsageStatistics::fromTokens(1, 1), toolCalls: $calls);
            },
        );

        $skill = $this->skill(self::SKILL_UID, 'style', $onDemandAllowedTools);
        $approvals = new InMemorySkillApprovalRepository();
        $this->approvals = $approvals;

        $lookup = new FixedSkillLoadLookup();
        if ($approve) {
            $approvals->add(self::SKILL_UID, 1, $skill->getVersionDigest(), SkillVersionDigest::fieldsOf($skill), 'verified', 1);
        }

        $configuration = $this->configuration();
        if ($attachOnDemand) {
            $configuration->addSkillOnDemand($skill);
            $lookup->onDemand[self::CONFIGURATION_UID] = [self::SKILL_UID];
            $lookup->digests[self::SKILL_UID]         = $skill->getVersionDigest();
            $lookup->trustLevels[self::SKILL_UID]     = $skill->getTrustLevel();
        }

        if ($alwaysAllowedTools !== null) {
            $configuration->addSkill($this->skill(8, 'restricting', $alwaysAllowedTools));
        }

        $composer = new SkillComposer(
            SkillComposer::DEFAULT_MAX_BYTES,
            SkillTrustLevel::UNTRUSTED,
            new SkillInstructionPolicy($approvals, new FixedSkillSourceLookup([1 => SkillTrustLevel::VERIFIED]), SkillTrustLevel::VERIFIED),
        );
        $registry = new ToolRegistry([
            new FakeTool('noop'),
            new FakeTool('secret'),
            new FakeTool('write', effect: ToolEffect::IDEMPOTENT_WRITE),
            new LoadSkillTool(),
            new FakeInputTool('ask_user'),
        ]);
        $window   = $admits === null ? null : $this->window($admits);
        $service  = new ToolLoopService(
            $manager,
            $registry,
            new ToolCallPolicy(
                $registry,
                new FakeToolAvailability($enabled ?? $registry->names()),
                new AllowedToolsResolver($composer, $registry),
                new ToolDataClassResolver($registry),
                new TrustZoneResolver(),
                new DataClassEnforcementResolver(),
            ),
            skillInjection: new SkillInjectionService($composer, new NullLogger()),
            contextWindow: $windowWithoutAdmission ? $this->plainWindow() : $window,
            skillLoad: new SkillLoadService($composer, $lookup),
        );

        return [$service, $configuration, $window];
    }

    private function plainWindow(): ContextWindowManagerInterface
    {
        return new class implements ContextWindowManagerInterface {
            public function fit(array $messages, LlmConfiguration $configuration, ?ChatOptions $options, ?UsageStatistics $lastUsage, array $toolSpecs = [], string $injectedText = '', ?string $effectiveSystemPrompt = null, ?Model $resolvedModel = null): ContextFitResult
            {
                return new ContextFitResult($messages, false, 0, 0, 10, 100, false, 1.0, ContextBudgetBreakdown::none());
            }
        };
    }

    /**
     * @return ContextWindowManagerInterface&SectionAdmissionInterface&object{begun: int}
     */
    private function window(bool $admits): ContextWindowManagerInterface&SectionAdmissionInterface
    {
        return new class ($admits) implements ContextWindowManagerInterface, SectionAdmissionInterface {
            public int $begun = 0;

            public function __construct(private readonly bool $admits) {}

            public function beginRun(): void
            {
                ++$this->begun;
            }

            public function fit(array $messages, LlmConfiguration $configuration, ?ChatOptions $options, ?UsageStatistics $lastUsage, array $toolSpecs = [], string $injectedText = '', ?string $effectiveSystemPrompt = null, ?Model $resolvedModel = null): ContextFitResult
            {
                return new ContextFitResult($messages, false, 0, 0, 10, 100, false, 1.0, ContextBudgetBreakdown::none());
            }

            public function admitsSection(array $messages, string $section, LlmConfiguration $configuration, ?ChatOptions $options, array $toolSpecs = [], ?string $effectiveSystemPrompt = null, ?Model $resolvedModel = null): bool
            {
                return $this->admits;
            }
        };
    }

    private function loadCall(string $id): ToolCall
    {
        return new ToolCall($id, LoadSkillTool::NAME, ['skill_uid' => self::SKILL_UID]);
    }

    /**
     * @return list<string>
     */
    private function offeredNames(int $send): array
    {
        return array_map(static fn(ToolSpec $spec): string => $spec->name, $this->sends[$send]['specs']);
    }

    private function spec(int $send, string $name): ToolSpec
    {
        foreach ($this->sends[$send]['specs'] as $spec) {
            if ($spec->name === $name) {
                return $spec;
            }
        }

        self::fail('No spec named ' . $name);
    }

    private function systemText(int $send): string
    {
        $first = $this->sends[$send]['messages'][0];
        if ($first instanceof ChatMessage && $first->isSystem()) {
            return $first->content;
        }

        return '';
    }

    private function toolResult(int $send, string $callId): string
    {
        foreach ($this->sends[$send]['messages'] as $message) {
            if ($message instanceof ChatMessage && $message->toolCallId === $callId) {
                return $message->content;
            }
        }

        self::fail('No tool result for ' . $callId);
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', self::CONFIGURATION_UID);
        $configuration->setIdentifier('skill-load');
        $configuration->setLlmModel($model);
        $configuration->setSystemPrompt(self::CONFIG_SYSTEM_PROMPT);

        return $configuration;
    }

    private function skill(int $uid, string $identifier, ?string $allowedTools): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', $uid);
        $skill->setSource(1);
        $skill->setIdentifier($identifier);
        $skill->setName($uid === self::SKILL_UID ? 'House style' : 'Restricting');
        $skill->setDescription($uid === self::SKILL_UID ? 'How we write.' : 'Restricts tools.');
        $skill->setBody($uid === self::SKILL_UID ? self::BODY : 'Restricting body.');
        $skill->setBodyChecksum(hash('sha256', $skill->getBody()));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        if ($allowedTools !== null) {
            $skill->setAllowedTools($allowedTools);
        }

        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }
}
