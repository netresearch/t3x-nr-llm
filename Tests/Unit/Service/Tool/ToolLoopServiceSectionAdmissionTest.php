<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

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
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\Context\SectionAdmissionInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
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

    private int $providerCalls = 0;

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

    private function service(bool $admits, bool $approve = true, bool $windowWithoutAdmission = false): ToolLoopService
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

        $sources  = new FixedSkillSourceLookup([1 => SkillTrustLevel::VERIFIED]);
        $policy   = new SkillInstructionPolicy($approvals, $sources, SkillTrustLevel::VERIFIED);
        $composer = new SkillComposer(SkillComposer::DEFAULT_MAX_BYTES, SkillTrustLevel::UNTRUSTED, $policy);
        $registry = new ToolRegistry([new FakeTool('noop')]);
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['skills' => []]);

        $snippet = new PromptSnippet();
        $snippet->setIdentifier('tone');
        $snippet->setName('Tone');
        $snippet->setSnippet('Use the formal register.');
        $snippet->setCallerSystemDigest((new SnippetCallerMark())->digestOf($snippet));

        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturn([$snippet]);

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
            contextWindow: $windowWithoutAdmission ? $this->plainWindow() : $this->window($admits),
            snippetResolver: new ConfigurationSnippetResolver($repository, new PromptSnippetComposer()),
            skillPinCheck: new SkillPinCheck($approvals, $sources, new FixedSkillRecordLookup([3]), new SkillComposerFactory($extensionConfiguration)),
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

    private function configuration(bool $optIn = false): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('section-admission');
        $configuration->setLlmModel($model);
        $configuration->setSystemPrompt(self::CONFIG_SYSTEM_PROMPT);
        $configuration->setSnippetTags('style');
        $configuration->setSnippetsInCallerSystemMessage($optIn);
        $configuration->addSkill($this->skill());

        return $configuration;
    }

    private function skill(): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', 3);
        $skill->setSource(1);
        $skill->setIdentifier('cfg');
        $skill->setName('Config Skill');
        $skill->setBody(self::BODY);
        $skill->setBodyChecksum(hash('sha256', self::BODY));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }
}
