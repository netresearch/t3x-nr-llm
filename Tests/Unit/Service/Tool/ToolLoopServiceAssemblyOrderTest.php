<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunRequestCodec;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\MessageShaper;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Characterisation of the order in which context enters the agent loop's
 * outgoing message list (#637).
 *
 * The order is emergent: three hard-wired sites contribute to it (the skill
 * injection, the baked system prompt, the forced snippets) and nothing states
 * the resulting sequence. These tests state it, for all four loop variants —
 * no augmentation (the production path), playground, dry run, and a queued run
 * rehydrated through {@see AgentRunRequestCodec}.
 *
 * The load-bearing invariant is the lead position of the system prompt. The
 * manager applies the configuration's system prompt through
 * {@see MessageShaper::applySystemPrompt()}, which is suppressed by ANY system
 * message already in the list. A snippet system message ahead of the baked
 * prompt therefore does not reorder the transcript — it silently deletes the
 * configuration's system prompt from the run.
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(AgentRunRequestCodec::class)]
final class ToolLoopServiceAssemblyOrderTest extends TestCase
{
    private const CONFIG_SYSTEM_PROMPT = 'You are the configured assistant.';

    private const RUN_SYSTEM_PROMPT = 'You are the per-run assistant.';

    private const SKILL_HEADING = '### Skill: Config Skill';

    private const SNIPPET_A = "tone:\nUse the formal register.";

    private const SNIPPET_B = "glossary:\nRender 'Beitrag' as 'article'.";

    private const USER_TURN = 'translate this';

    /**
     * The production path: no {@see RunAugmentation} is constructed anywhere
     * outside the playground, so `assemble()` returns at its early exit. Only
     * the skill block is applied — no system message is added here at all, and
     * the configuration's system prompt is left to the manager's shaper.
     */
    #[Test]
    public function aRunWithoutAugmentationAddsNoSystemMessageAndOnlyPrependsTheSkillBlock(): void
    {
        $sent = $this->runAndCaptureMessages(null);

        self::assertSame(['user'], $this->roles($sent));
        $content = $this->contentOf($sent[0]);
        self::assertStringContainsString(self::SKILL_HEADING, $content);
        self::assertStringEndsWith(self::USER_TURN, $content);
        // The configuration's system prompt is NOT baked on this path.
        self::assertStringNotContainsString(self::CONFIG_SYSTEM_PROMPT, $content);
    }

    /**
     * The playground path. Exact sequence: the baked system prompt, then one
     * system message per forced snippet in declaration order, then the
     * original turns with the skill block prepended to the first user message.
     */
    #[Test]
    public function theBakedSystemPromptLeadsTheSnippetSystemMessagesAndTheSkillBlockStaysInTheUserTurn(): void
    {
        $sent = $this->runAndCaptureMessages(new RunAugmentation(forcedSnippets: [
            $this->snippet('tone', 'Use the formal register.'),
            $this->snippet('glossary', "Render 'Beitrag' as 'article'."),
        ]));

        self::assertSame(['system', 'system', 'system', 'user'], $this->roles($sent));
        self::assertSame(self::CONFIG_SYSTEM_PROMPT, $this->contentOf($sent[0]));
        self::assertSame(self::SNIPPET_A, $this->contentOf($sent[1]));
        self::assertSame(self::SNIPPET_B, $this->contentOf($sent[2]));

        $userContent = $this->contentOf($sent[3]);
        self::assertStringContainsString(self::SKILL_HEADING, $userContent);
        self::assertStringEndsWith(self::USER_TURN, $userContent);
        // The skill block is never escalated into the system role.
        self::assertStringNotContainsString(self::SKILL_HEADING, $this->contentOf($sent[0]));
        self::assertStringNotContainsString(self::SKILL_HEADING, $this->contentOf($sent[1]));
        self::assertStringNotContainsString(self::SKILL_HEADING, $this->contentOf($sent[2]));
    }

    /**
     * A per-run system prompt on the options replaces the configuration's and
     * keeps the same lead position — the override changes the text, not the
     * order.
     */
    #[Test]
    public function aPerRunSystemPromptOverridesTheConfigurationsAndKeepsTheLeadPosition(): void
    {
        $sent = $this->runAndCaptureMessages(
            new RunAugmentation(forcedSnippets: [$this->snippet('tone', 'Use the formal register.')]),
            new ToolOptions(systemPrompt: self::RUN_SYSTEM_PROMPT),
        );

        self::assertSame(['system', 'system', 'user'], $this->roles($sent));
        self::assertSame(self::RUN_SYSTEM_PROMPT, $this->contentOf($sent[0]));
        self::assertSame(self::SNIPPET_A, $this->contentOf($sent[1]));
    }

    /**
     * An empty system prompt on both the options and the configuration adds no
     * lead message, so the first system message a run sends is the snippet.
     * This is the shape the invariant below is about.
     */
    #[Test]
    public function withoutAnySystemPromptTheSnippetSystemMessageBecomesTheFirstMessage(): void
    {
        $configuration = $this->configuration(systemPrompt: '');

        $sent = $this->runAndCaptureMessages(
            new RunAugmentation(forcedSnippets: [$this->snippet('tone', 'Use the formal register.')]),
            configuration: $configuration,
        );

        self::assertSame(['system', 'user'], $this->roles($sent));
        self::assertSame(self::SNIPPET_A, $this->contentOf($sent[0]));
    }

    /**
     * A dry run assembles exactly what a live run sends and calls no provider —
     * the playground's preview is the transcript, not an approximation of it.
     */
    #[Test]
    public function aDryRunAssemblesByteForByteWhatALiveRunSends(): void
    {
        $augmentation = fn(bool $dryRun): RunAugmentation => new RunAugmentation(
            forcedSnippets: [$this->snippet('tone', 'Use the formal register.')],
            dryRun: $dryRun,
        );

        $live = $this->snapshot($this->runAndCaptureMessages($augmentation(false)));

        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->expects(self::never())->method('chatWithToolsForConfiguration');
        $manager->expects(self::never())->method('chatWithConfiguration');

        $trace = new RunTrace();
        $this->service($manager)->runLoop(
            [ChatMessage::user(self::USER_TURN)],
            $this->configuration(),
            ToolExecutionContext::none(),
            null,
            null,
            null,
            $trace,
            $augmentation(true),
        );

        $steps = $trace->getSteps();
        self::assertCount(1, $steps);
        self::assertSame(RunStep::KIND_ASSEMBLED, $steps[0]->kind);
        self::assertSame($live, $steps[0]->messagesSent);
    }

    /**
     * A resume (ADR-084) hands the loop an already-assembled transcript and
     * skips assembly, so neither the system prompt nor the skill block is
     * applied a second time.
     */
    #[Test]
    public function aResumeSkipsAssemblySoNeitherTheSystemPromptNorTheSkillBlockIsDuplicated(): void
    {
        $transcript = [
            ChatMessage::system(self::CONFIG_SYSTEM_PROMPT),
            ChatMessage::user(self::SKILL_HEADING . "\n\n" . self::USER_TURN),
        ];

        $sent = $this->runAndCaptureMessages(
            null,
            messages: $transcript,
            skipAssembly: true,
        );

        self::assertSame($transcript, $sent);
    }

    /**
     * A queued run travels through the codec as uids and is rehydrated at
     * execution time. The rehydrated augmentation must assemble the identical
     * transcript — otherwise `run()` and `enqueue()` + `runQueued()` of the same
     * request would send different prompts.
     *
     * Two snippets, in a declaration order that is not the ascending uid order,
     * so the uid list the codec itself is responsible for is pinned too:
     * `dehydrate()` maps the forced snippets to uids, `uidList()` carries them
     * through rehydration and `snippetsByUids()` hands them to the repository
     * unchanged (unlike `skillsByUids()`, which rebuilds the order itself).
     * Rebuilding the order from the repository result is NOT this test's job —
     * that contract belongs to
     * {@see \Netresearch\NrLlm\Tests\Functional\Repository\PromptSnippetRepositoryTest::findByUidsPreservesInputOrder()}.
     */
    #[Test]
    public function aQueuedRunRehydratedByTheCodecAssemblesTheSameOrderAsTheDirectRun(): void
    {
        $tone = $this->snippet('tone', 'Use the formal register.');
        $tone->_setProperty('uid', 9);

        $glossary = $this->snippet('glossary', "Render 'Beitrag' as 'article'.");
        $glossary->_setProperty('uid', 3);

        $configuration = $this->configuration();

        $direct = $this->snapshot($this->runAndCaptureMessages(
            new RunAugmentation(forcedSnippets: [$tone, $glossary]),
            configuration: $configuration,
        ));

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($configuration);

        $snippetRepository = $this->createMock(PromptSnippetRepository::class);
        $snippetRepository->expects(self::once())
            ->method('findByUids')
            ->with([9, 3])
            ->willReturn([$tone, $glossary]);

        $codec = new AgentRunRequestCodec($configurationRepository, null, $snippetRepository);

        $payload = json_encode($codec->dehydrate(new AgentRunRequest(
            configuration: $configuration,
            messages: [ChatMessage::user(self::USER_TURN)],
            actor: AiActorContext::backendUser(7),
            augmentation: new RunAugmentation(forcedSnippets: [$tone, $glossary]),
        )));
        self::assertIsString($payload);

        $restored = $codec->rehydrate($this->queuedRun($payload));

        $resumed = $this->snapshot($this->runAndCaptureMessages(
            $restored->augmentation,
            configuration: $restored->configuration,
        ));

        self::assertSame($direct, $resumed);
        self::assertSame(['system', 'system', 'system', 'user'], array_column($resumed, 'role'));
        self::assertSame(self::CONFIG_SYSTEM_PROMPT, $this->contentOf($resumed[0]));
        self::assertSame(self::SNIPPET_A, $this->contentOf($resumed[1]));
        self::assertSame(self::SNIPPET_B, $this->contentOf($resumed[2]));
    }

    /**
     * The invariant the comment in `assemble()` states, as an assertion: with
     * the baked prompt in the lead, the manager's shaper finds the
     * configuration's own system prompt already first and changes nothing.
     */
    #[Test]
    public function theBakedSystemPromptSatisfiesTheShapersGuardWithTheRightMessage(): void
    {
        $assembled = $this->snapshot($this->runAndCaptureMessages(
            new RunAugmentation(forcedSnippets: [$this->snippet('tone', 'Use the formal register.')]),
        ));

        $shaped = (new MessageShaper())->applySystemPrompt(
            $assembled,
            ['system_prompt' => self::CONFIG_SYSTEM_PROMPT],
        );

        self::assertSame($assembled, $shaped);
        self::assertSame(self::CONFIG_SYSTEM_PROMPT, $this->contentOf($shaped[0]));
    }

    /**
     * The shaper's guard from the other side, and the reason the lead position
     * is load-bearing: once ANY system message leads the list, a system prompt
     * handed to the shaper afterwards is dropped for the whole run — silently,
     * with no error anywhere. Here the leading message is a snippet, because the
     * configuration carries no system prompt of its own and nothing is baked.
     *
     * This is not the guard against moving the snippet loop ahead of the bake in
     * `assemble()` — with an empty prompt that reorder is a no-op, so this test
     * cannot go red on it. That guard is
     * {@see self::theBakedSystemPromptLeadsTheSnippetSystemMessagesAndTheSkillBlockStaysInTheUserTurn()},
     * {@see self::aPerRunSystemPromptOverridesTheConfigurationsAndKeepsTheLeadPosition()}
     * and {@see self::theBakedSystemPromptSatisfiesTheShapersGuardWithTheRightMessage()}.
     *
     * The two sides can genuinely disagree in production: `assemble()` runs once
     * on the primary configuration, before the middleware pipeline, while
     * `FallbackMiddleware` re-enters the pipeline with `withConfiguration()` and
     * the manager re-reads the options off that configuration. A primary without
     * a system prompt plus forced snippets, falling back to a configuration that
     * has one, produces exactly the pair asserted below.
     */
    #[Test]
    public function aLeadingSystemMessageSuppressesASystemPromptAppliedAfterAssembly(): void
    {
        $withoutBakedLead = $this->snapshot($this->runAndCaptureMessages(
            new RunAugmentation(forcedSnippets: [$this->snippet('tone', 'Use the formal register.')]),
            configuration: $this->configuration(systemPrompt: ''),
        ));

        self::assertSame(self::SNIPPET_A, $this->contentOf($withoutBakedLead[0]));

        $shaped = (new MessageShaper())->applySystemPrompt(
            $withoutBakedLead,
            ['system_prompt' => self::CONFIG_SYSTEM_PROMPT],
        );

        self::assertSame($withoutBakedLead, $shaped);
        foreach ($shaped as $message) {
            self::assertStringNotContainsString(self::CONFIG_SYSTEM_PROMPT, $this->contentOf($message));
        }
    }

    /**
     * Run the loop once and return the message list the manager received.
     *
     * @param list<ChatMessage|array<string, mixed>>|null $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function runAndCaptureMessages(
        ?RunAugmentation $augmentation,
        ?ToolOptions $options = null,
        ?LlmConfiguration $configuration = null,
        ?array $messages = null,
        bool $skipAssembly = false,
    ): array {
        $sent = [];

        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(
            static function (array $received) use (&$sent): CompletionResponse {
                $sent = $received;

                return new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1));
            },
        );

        $this->service($manager)->runLoop(
            $messages ?? [ChatMessage::user(self::USER_TURN)],
            $configuration ?? $this->configuration(),
            ToolExecutionContext::none(),
            null,
            $options,
            null,
            null,
            $augmentation,
            $skipAssembly,
        );

        self::assertNotSame([], $sent, 'The loop did not reach the provider call.');

        /** @var list<ChatMessage|array<string, mixed>> $sent */
        return $sent;
    }

    private function service(LlmServiceManagerInterface $manager): ToolLoopService
    {
        $registry = new ToolRegistry([new FakeTool('noop')]);

        return new ToolLoopService(
            $manager,
            $registry,
            new ToolCallPolicy(
                $registry,
                new FakeToolAvailability($registry->names()),
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
     * A LOCAL-trust-zone configuration (so the composite gate offers the fake
     * tool at all — see ToolLoopServiceAugmentationTest) carrying one skill and,
     * by default, a system prompt.
     */
    private function configuration(string $systemPrompt = self::CONFIG_SYSTEM_PROMPT): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('assembly-order');
        $configuration->setLlmModel($model);
        $configuration->setSystemPrompt($systemPrompt);
        $configuration->addSkill($this->skill());

        return $configuration;
    }

    private function skill(): Skill
    {
        $body = 'Always answer in JSON.';

        $skill = new Skill();
        $skill->setSource(1);
        $skill->setIdentifier('cfg');
        $skill->setName('Config Skill');
        $skill->setBody($body);
        $skill->setBodyChecksum(hash('sha256', $body));
        $skill->setSupportStatus(SupportStatus::FULL->value);
        $skill->setEnabled(true);
        $skill->setOrphaned(false);

        return $skill;
    }

    private function snippet(string $name, string $text): PromptSnippet
    {
        $snippet = new PromptSnippet();
        $snippet->setName($name);
        $snippet->setSnippet($text);

        return $snippet;
    }

    private function queuedRun(string $payload): AgentRun
    {
        return new AgentRun(
            uid: 1,
            uuid: 'run-uuid',
            status: 'queued',
            configurationUid: 42,
            configurationIdentifier: 'assembly-order',
            beUser: 7,
            iterations: 0,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
            queuedRequest: $payload,
        );
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<string>
     */
    private function roles(array $messages): array
    {
        return array_map(
            static function (ChatMessage|array $message): string {
                if ($message instanceof ChatMessage) {
                    return $message->role;
                }

                return is_string($message['role'] ?? null) ? $message['role'] : '';
            },
            $messages,
        );
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function contentOf(ChatMessage|array $message): string
    {
        if ($message instanceof ChatMessage) {
            return $message->content;
        }

        return is_string($message['content'] ?? null) ? $message['content'] : '';
    }

    /**
     * The array form the trace records, so an assembled transcript and a sent
     * one are comparable with assertSame().
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(array $messages): array
    {
        return array_map(
            static fn(ChatMessage|array $message): array => $message instanceof ChatMessage
                ? $message->toArray()
                : $message,
            $messages,
        );
    }
}
