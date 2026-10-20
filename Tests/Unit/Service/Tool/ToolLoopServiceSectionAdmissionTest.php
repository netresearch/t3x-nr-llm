<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ModelSelectionMode;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextBudgetBreakdown;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Exception\SkillSectionNotAdmittedException;
use Netresearch\NrLlm\Service\Context\ContextWindowManager;
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\Context\SectionAdmissionInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Prompt\SnippetCallerMark;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillRecordLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The agent loop's start (ADR-214 items 7 and 8): the approved sections
 * composed at a start are admitted against the context window before the
 * first provider call, and refused rather than evicted; a resume composes
 * nothing and admits nothing again. Marked snippets join a system message the
 * caller sent, never one the loop bakes itself.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceSectionAdmissionTest extends TestCase
{
    private const BODY = 'Always answer in JSON.';

    private const CONFIG_SYSTEM_PROMPT = 'You are the configured assistant.';

    private const BLOCK = "Tone:\nUse the formal register.";

    private const UNMARKED_BLOCK = "Draft notes:\nNot reviewed by an administrator.";

    private const FORCED_BLOCK = "Legal:\nCite the imprint.";

    private int $providerCalls = 0;

    private string $body = self::BODY;

    /** @var list<ChatMessage|array<string, mixed>> */
    private array $sent = [];

    #[Test]
    public function sectionsThatDoNotFitStopTheStartBeforeAnyProviderCall(): void
    {
        try {
            $this->service(admits: false)->runLoop([ChatMessage::user('go')], $this->configuration(), ToolExecutionContext::none(), null);
            self::fail('The start was not refused.');
        } catch (SkillSectionNotAdmittedException $e) {
            self::assertSame(['cfg'], $e->skillIdentifiers);
            self::assertStringContainsString('"cfg"', $e->getMessage());
        }

        self::assertSame(0, $this->providerCalls);
    }

    #[Test]
    public function aWindowThatCannotAdmitStopsAStartWithSections(): void
    {
        $this->expectException(SkillSectionNotAdmittedException::class);

        try {
            $this->service(admits: true, windowWithoutAdmission: true)->runLoop([ChatMessage::user('go')], $this->configuration(), ToolExecutionContext::none(), null);
        } finally {
            self::assertSame(0, $this->providerCalls);
        }
    }

    #[Test]
    public function sectionsThatFitStartTheRun(): void
    {
        $this->service(admits: true)->runLoop([ChatMessage::user('go')], $this->configuration(), ToolExecutionContext::none(), null);

        self::assertSame(1, $this->providerCalls);
    }

    /**
     * The manager is shared: another run may have raised its calibration.
     * The start admission measures afresh, so sections that fit at the seed
     * calibration start the run however far the last run raised it.
     */
    #[Test]
    public function theStartAdmissionDoesNotInheritAnotherRunsCalibration(): void
    {
        $manager  = new ContextWindowManager();
        $messages = [ChatMessage::user('go')];
        $first    = $manager->fit($messages, $this->configuration(window: 10000), null, null);
        $manager->fit($messages, $this->configuration(window: 10000), null, UsageStatistics::fromTokens($first->estimatedTokens * 400, 1));

        $this->service(admits: true, window: $manager)->runLoop([ChatMessage::user('go')], $this->configuration(window: 10000), ToolExecutionContext::none(), null);

        self::assertSame(1, $this->providerCalls);
    }

    #[Test]
    public function aStartWithoutSectionsIsNotAdmissionChecked(): void
    {
        $this->service(admits: false, approve: false)->runLoop([ChatMessage::user('go')], $this->configuration(), ToolExecutionContext::none(), null);

        self::assertSame(1, $this->providerCalls);
    }

    /**
     * A section the run already holds is not re-admitted (ADR-214 item 7): a
     * resume replays the transcript it suspended with.
     */
    #[Test]
    public function aResumeIsNotAdmissionChecked(): void
    {
        $skill = $this->skill();
        $state = new SuspendedRunState(
            [ChatMessage::system(self::CONFIG_SYSTEM_PROMPT . "\n\n## Approved skills\n" . self::BODY)->toTranscriptArray(), ChatMessage::user('go')->toTranscriptArray(), ChatMessage::assistantToolCalls([new ToolCall('c1', 'noop', [])], '')->toTranscriptArray()],
            [(new ToolCall('c1', 'noop', []))->toArray()],
            1,
            1,
            1,
            null,
            [],
            skillPins: [new SkillPin(3, 1, $skill->getVersionDigest())],
        );

        $this->service(admits: false)->resume($state, true, $this->configuration(), ToolExecutionContext::none());

        self::assertSame(1, $this->providerCalls);
    }

    #[Test]
    public function aMarkedSnippetJoinsTheCallersSystemMessageInTheLoop(): void
    {
        $this->service(admits: true, approve: false)->runLoop(
            [ChatMessage::system('Chat identity.'), ChatMessage::user('go')],
            $this->configuration(optIn: true),
            ToolExecutionContext::none(),
            null,
        );

        self::assertInstanceOf(ChatMessage::class, $this->sent[0]);
        self::assertSame("Chat identity.\n\n" . self::BLOCK, $this->sent[0]->content);
    }

    /**
     * The prompt the loop bakes in front of the sections already carries the
     * configuration's snippets; the caller-system path must not add them
     * a second time.
     */
    #[Test]
    public function aPromptTheLoopBakesGetsTheSnippetsOnce(): void
    {
        $this->service(admits: true)->runLoop([ChatMessage::user('go')], $this->configuration(optIn: true), ToolExecutionContext::none(), null);

        self::assertInstanceOf(ChatMessage::class, $this->sent[0]);
        self::assertSame(1, substr_count($this->sent[0]->content, self::BLOCK));
    }

    /**
     * A criteria-mode configuration's start is measured against the model
     * the send resolves to (ADR-143): sections that fit the configuration's
     * own 8192-token model but not the resolved 4096 one stop the start, and
     * the same sections start against a resolved large window.
     */
    #[Test]
    public function aStartInCriteriaModeIsMeasuredAgainstTheResolvedModel(): void
    {
        $this->body = str_repeat('word ', 3500);
        $config     = $this->configuration(window: 8192, criteria: true);

        try {
            $this->service(admits: true, window: new ContextWindowManager(), selection: $this->selecting($this->model(4096)))
                ->runLoop([ChatMessage::user('go')], $config, ToolExecutionContext::none(), null);
            self::fail('The start was not refused against the resolved 4096-token window.');
        } catch (SkillSectionNotAdmittedException) {
            self::assertSame(0, $this->providerCalls);
        }

        $this->service(admits: true, window: new ContextWindowManager(), selection: $this->selecting($this->model(100000)))
            ->runLoop([ChatMessage::user('go')], $this->configuration(window: 8192, criteria: true), ToolExecutionContext::none(), null);

        self::assertSame(1, $this->providerCalls);
    }

    /**
     * The precondition the test above rests on: the sections fit 8192 tokens
     * and do not fit 4096.
     */
    #[Test]
    public function theCriteriaSectionsFit8192ButNot4096(): void
    {
        $this->body = str_repeat('word ', 3500);

        $this->service(admits: true, window: new ContextWindowManager())->runLoop([ChatMessage::user('go')], $this->configuration(window: 8192), ToolExecutionContext::none(), null);
        self::assertSame(1, $this->providerCalls);

        $this->expectException(SkillSectionNotAdmittedException::class);
        $this->service(admits: true, window: new ContextWindowManager())->runLoop([ChatMessage::user('go')], $this->configuration(window: 4096), ToolExecutionContext::none(), null);
    }

    #[Test]
    public function aStartInCriteriaModeWithoutAResolvableModelIsRefused(): void
    {
        $this->expectException(SkillSectionNotAdmittedException::class);

        try {
            $this->service(admits: true, selection: $this->selecting(null))->runLoop([ChatMessage::user('go')], $this->configuration(criteria: true), ToolExecutionContext::none(), null);
        } finally {
            self::assertSame(0, $this->providerCalls);
        }
    }

    /**
     * An unknown window admits nothing at the start: a context length of 0
     * or below stops a run with sections; a known one starts it.
     */
    #[Test]
    public function aStartAgainstAnUnknownWindowIsRefused(): void
    {
        foreach ([0, -1] as $contextLength) {
            try {
                $this->service(admits: true, window: new ContextWindowManager())->runLoop([ChatMessage::user('go')], $this->configuration(window: $contextLength), ToolExecutionContext::none(), null);
                self::fail(sprintf('A start against a context length of %d was not refused.', $contextLength));
            } catch (SkillSectionNotAdmittedException) {
                self::assertSame(0, $this->providerCalls);
            }
        }

        $this->service(admits: true, window: new ContextWindowManager())->runLoop([ChatMessage::user('go')], $this->configuration(window: 10000), ToolExecutionContext::none(), null);

        self::assertSame(1, $this->providerCalls);
    }

    /**
     * A caller's max_tokens larger than the window leaves no budget: the
     * start is refused. A smaller one starts the run.
     */
    #[Test]
    public function aCallerReserveLargerThanTheWindowRefusesTheStart(): void
    {
        try {
            $this->service(admits: true, window: new ContextWindowManager())->runLoop([ChatMessage::user('go')], $this->configuration(window: 10000), ToolExecutionContext::none(), null, new ToolOptions(maxTokens: 12000));
            self::fail('The start was not refused.');
        } catch (SkillSectionNotAdmittedException) {
            self::assertSame(0, $this->providerCalls);
        }

        $this->service(admits: true, window: new ContextWindowManager())->runLoop([ChatMessage::user('go')], $this->configuration(window: 10000), ToolExecutionContext::none(), null, new ToolOptions(maxTokens: 2000));

        self::assertSame(1, $this->providerCalls);
    }

    /**
     * The other direction on the plain path: an unmarked snippet the
     * configuration selects does not join the caller's system message.
     */
    #[Test]
    public function anUnmarkedSnippetDoesNotJoinTheCallersSystemMessage(): void
    {
        $this->service(admits: true, approve: false)->runLoop(
            [ChatMessage::system('Chat identity.'), ChatMessage::user('go')],
            $this->configuration(optIn: true),
            ToolExecutionContext::none(),
            null,
        );

        self::assertStringNotContainsString(self::UNMARKED_BLOCK, $this->wire());
        self::assertSame(1, substr_count($this->wire(), self::BLOCK));
    }

    /**
     * A run with forced items (playground, queued runs) whose caller sent
     * its own system message: only the marked snippet reaches the system
     * channel, once; the unmarked one and the configuration's prompt do not.
     */
    #[Test]
    public function anAugmentedRunWithACallerSystemMessageSendsOnlyMarkedSnippetsOnce(): void
    {
        $this->service(admits: true, approve: false)->runLoop(
            [ChatMessage::system('Chat identity.'), ChatMessage::user('go')],
            $this->configuration(optIn: true),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            null,
            new RunAugmentation(),
        );

        self::assertSame(1, substr_count($this->wire(), self::BLOCK));
        self::assertStringNotContainsString(self::UNMARKED_BLOCK, $this->wire());
        self::assertStringNotContainsString(self::CONFIG_SYSTEM_PROMPT, $this->wire());
    }

    /**
     * A forced snippet that is marked and already joined the caller's
     * system message is not sent a second time.
     */
    #[Test]
    public function aForcedMarkedSnippetJoinsTheCallersSystemMessageOnce(): void
    {
        $forced = new PromptSnippet();
        $forced->setIdentifier('tone');
        $forced->setName('Tone');
        $forced->setSnippet('Use the formal register.');
        $forced->setCallerSystemDigest((new SnippetCallerMark())->digestOf($forced));

        $this->service(admits: true, approve: false)->runLoop(
            [ChatMessage::system('Chat identity.'), ChatMessage::user('go')],
            $this->configuration(optIn: true),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            null,
            new RunAugmentation(forcedSnippets: [$forced]),
        );

        self::assertSame(1, substr_count($this->wire(), self::BLOCK));
    }

    /**
     * A forced snippet reaches a caller's own system message only as a
     * tag-selected one does: marked, and with the configuration's opt-in.
     * Unmarked it is not sent; marked it joins the caller's message itself.
     */
    #[Test]
    public function aForcedSnippetReachesTheCallersSystemMessageOnlyWhenMarked(): void
    {
        $forced = $this->forcedSnippet();

        $this->runForced($forced, optIn: true);
        self::assertStringNotContainsString(self::FORCED_BLOCK, $this->wire());

        $forced->setCallerSystemDigest((new SnippetCallerMark())->digestOf($forced));
        $this->runForced($forced, optIn: true);
        self::assertSame(1, substr_count($this->wire(), self::FORCED_BLOCK));
        self::assertInstanceOf(ChatMessage::class, $this->sent[0]);
        self::assertStringStartsWith('Chat identity.', $this->sent[0]->content);
        self::assertStringContainsString(self::FORCED_BLOCK, $this->sent[0]->content, "it joins the caller's message, not one of its own");
    }

    /**
     * Without the configuration's opt-in even a marked forced snippet does
     * not reach the caller's system message.
     */
    #[Test]
    public function aMarkedForcedSnippetNeedsTheOptIn(): void
    {
        $forced = $this->forcedSnippet();
        $forced->setCallerSystemDigest((new SnippetCallerMark())->digestOf($forced));

        $this->runForced($forced, optIn: false);

        self::assertStringNotContainsString(self::FORCED_BLOCK, $this->wire());
    }

    private function runForced(PromptSnippet $forced, bool $optIn): void
    {
        $this->service(admits: true, approve: false)->runLoop(
            [ChatMessage::system('Chat identity.'), ChatMessage::user('go')],
            $this->configuration(optIn: $optIn),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            null,
            new RunAugmentation(forcedSnippets: [$forced]),
        );
    }

    /**
     * Without a caller system message a forced snippet is sent whether or
     * not it is marked, as before.
     */
    #[Test]
    public function aForcedUnmarkedSnippetIsSentWithoutACallerSystemMessage(): void
    {
        $this->service(admits: true, approve: false)->runLoop(
            [ChatMessage::user('go')],
            $this->configuration(),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            null,
            new RunAugmentation(forcedSnippets: [$this->forcedSnippet()]),
        );

        self::assertSame(1, substr_count($this->wire(), self::FORCED_BLOCK));
    }

    private function forcedSnippet(): PromptSnippet
    {
        $forced = new PromptSnippet();
        $forced->setIdentifier('legal');
        $forced->setName('Legal');
        $forced->setSnippet('Cite the imprint.');

        return $forced;
    }

    /**
     * The other direction: without a caller system message the augmented
     * run bakes the configuration's prompt with every tag-selected snippet,
     * each once, as before (ADR-031).
     */
    #[Test]
    public function anAugmentedRunWithoutACallerSystemMessageBakesTheConfigurationPrompt(): void
    {
        $this->service(admits: true, approve: false)->runLoop(
            [ChatMessage::user('go')],
            $this->configuration(optIn: true),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            null,
            new RunAugmentation(),
        );

        self::assertStringContainsString(self::CONFIG_SYSTEM_PROMPT, $this->wire());
        self::assertSame(1, substr_count($this->wire(), self::BLOCK));
        self::assertSame(1, substr_count($this->wire(), self::UNMARKED_BLOCK));
    }

    private function wire(): string
    {
        $text = '';
        foreach ($this->sent as $message) {
            $text .= ($message instanceof ChatMessage ? $message->content : (is_string($message['content'] ?? null) ? $message['content'] : '')) . "\n---\n";
        }

        return $text;
    }

    private function selecting(?Model $resolved): ModelSelectionServiceInterface
    {
        $selection = self::createStub(ModelSelectionServiceInterface::class);
        $selection->method('resolveModel')->willReturn($resolved);

        return $selection;
    }

    private function model(int $contextLength): Model
    {
        $model = new Model();
        $model->setContextLength($contextLength);
        $model->setMaxOutputTokens(500);

        return $model;
    }

    private function service(bool $admits, bool $approve = true, bool $windowWithoutAdmission = false, ?ContextWindowManagerInterface $window = null, ?ModelSelectionServiceInterface $selection = null): ToolLoopService
    {
        $this->providerCalls = 0;
        $this->sent          = [];
        $manager             = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (array $messages): CompletionResponse {
                ++$this->providerCalls;
                /** @var list<ChatMessage|array<string, mixed>> $messages */
                $this->sent = $messages;

                return new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1));
            },
        );

        $skill     = $this->skill();
        $approvals = new InMemorySkillApprovalRepository();
        if ($approve) {
            $approvals->add(3, 1, $skill->getVersionDigest(), SkillVersionDigest::fieldsOf($skill), 'verified', 1);
        }

        $sources                = new FixedSkillSourceLookup([1 => SkillTrustLevel::VERIFIED]);
        $policy                 = new SkillInstructionPolicy($approvals, $sources, SkillTrustLevel::VERIFIED);
        $composer               = new SkillComposer(SkillComposer::DEFAULT_MAX_BYTES, SkillTrustLevel::UNTRUSTED, $policy);
        $registry               = new ToolRegistry([new FakeTool('noop')]);
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['skills' => []]);

        $snippet = new PromptSnippet();
        $snippet->setIdentifier('tone');
        $snippet->setName('Tone');
        $snippet->setSnippet('Use the formal register.');
        $snippet->setCallerSystemDigest((new SnippetCallerMark())->digestOf($snippet));

        $unmarked = new PromptSnippet();
        $unmarked->setIdentifier('draft');
        $unmarked->setName('Draft notes');
        $unmarked->setSnippet('Not reviewed by an administrator.');

        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturn([$snippet, $unmarked]);

        return new ToolLoopService(
            $manager,
            $registry,
            new ToolCallPolicy(
                $registry,
                new FakeToolAvailability($registry->names()),
                new AllowedToolsResolver($composer, $registry),
                new ToolDataClassResolver($registry),
                new TrustZoneResolver(),
                new DataClassEnforcementResolver(),
            ),
            skillInjection: new SkillInjectionService($composer, new NullLogger()),
            snippetComposer: new PromptSnippetComposer(),
            contextWindow: $window ?? ($windowWithoutAdmission ? $this->plainWindow() : $this->window($admits)),
            snippetResolver: new ConfigurationSnippetResolver($repository, new PromptSnippetComposer()),
            skillPinCheck: new SkillPinCheck($approvals, $sources, new FixedSkillRecordLookup([3]), new SkillComposerFactory($extensionConfiguration)),
            modelSelection: $selection,
        );
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

    private function window(bool $admits): ContextWindowManagerInterface&SectionAdmissionInterface
    {
        return new class ($admits) implements ContextWindowManagerInterface, SectionAdmissionInterface {
            public function __construct(private readonly bool $admits) {}

            public function beginRun(): void
            {
                // This fake answers admission from a fixed flag and carries no calibration state to reset.
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

    private function configuration(bool $optIn = false, int $window = 0, bool $criteria = false): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);
        $model->setContextLength($window);
        $model->setMaxOutputTokens(500);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('section-admission');
        $configuration->setLlmModel($model);
        $configuration->setSystemPrompt(self::CONFIG_SYSTEM_PROMPT);
        $configuration->setSnippetTags('style');
        $configuration->setSnippetsInCallerSystemMessage($optIn);
        $configuration->addSkill($this->skill());
        if ($criteria) {
            $configuration->setModelSelectionMode(ModelSelectionMode::CRITERIA->value);
        }

        return $configuration;
    }

    private function skill(): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', 3);
        $skill->setSource(1);
        $skill->setIdentifier('cfg');
        $skill->setName('Config Skill');
        $skill->setBody($this->body);
        $skill->setBodyChecksum(hash('sha256', $this->body));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }
}
