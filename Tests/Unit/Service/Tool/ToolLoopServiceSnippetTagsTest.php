<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
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

/**
 * The playground bake site is the second reader of the composed system prompt
 * (#638).
 *
 * The loop bakes the effective system prompt itself for an augmented run
 * instead of leaving it to the manager's shaper (see
 * {@see ToolLoopServiceAssemblyOrderTest} for why the lead position is
 * load-bearing). If that site took the raw configuration prompt while the
 * manager's planner composed the snippets in, the playground would preview a
 * transcript a live run never sends.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceSnippetTagsTest extends TestCase
{
    private const CONFIG_SYSTEM_PROMPT = 'You are the configured assistant.';

    private const SNIPPET_BLOCK = "Nova:\nYou are Nova.";

    #[Test]
    public function thePlaygroundBakesTheSameSystemPromptTheConfigurationComposes(): void
    {
        $sent = $this->runAndCaptureMessages($this->configuration('persona'), new RunAugmentation());

        self::assertSame('system', $this->roleOf($sent[0]));
        self::assertSame(
            self::CONFIG_SYSTEM_PROMPT . "\n\n" . self::SNIPPET_BLOCK,
            $this->contentOf($sent[0]),
        );
    }

    /**
     * A per-run override still wins on the text and keeps the lead position;
     * the configuration's snippets follow it, matching what the planner does
     * for a per-call override on the live paths.
     */
    #[Test]
    public function aPerRunSystemPromptOverrideStillCarriesTheConfigurationSnippets(): void
    {
        $sent = $this->runAndCaptureMessages(
            $this->configuration('persona'),
            new RunAugmentation(),
            new ToolOptions(systemPrompt: 'Per-run prompt.'),
        );

        self::assertSame("Per-run prompt.\n\n" . self::SNIPPET_BLOCK, $this->contentOf($sent[0]));
    }

    /**
     * The regression guard for the pinned assembly order: a configuration
     * without tags bakes exactly what it baked before the field existed.
     */
    #[Test]
    public function aConfigurationWithoutTagsBakesTheUnchangedSystemPrompt(): void
    {
        $sent = $this->runAndCaptureMessages($this->configuration(''), new RunAugmentation());

        self::assertSame(self::CONFIG_SYSTEM_PROMPT, $this->contentOf($sent[0]));
    }

    /**
     * Without a RunAugmentation the loop returns at its early exit, so the
     * production tool path adds no system message here at all — the composed
     * prompt reaches it through the manager's planner instead. This is the
     * invariant #637 pinned and this feature must not move.
     */
    #[Test]
    public function theProductionPathStillBakesNoSystemMessage(): void
    {
        $sent = $this->runAndCaptureMessages($this->configuration('persona'), null);

        self::assertSame('user', $this->roleOf($sent[0]));
        foreach ($sent as $message) {
            self::assertStringNotContainsString('Nova:', $this->contentOf($message));
        }
    }

    /**
     * A forced snippet the configuration already selects by tag reaches the
     * prompt once. The resolver dedups by identifier within the tag selection,
     * but the playground's forced list is a second source it never sees.
     */
    #[Test]
    public function aForcedSnippetTheConfigurationAlreadySelectsIsNotRepeated(): void
    {
        $sent = $this->runAndCaptureMessages(
            $this->configuration('persona'),
            new RunAugmentation(forcedSnippets: [$this->novaSnippet()]),
        );

        $prompt = implode("\n", array_map($this->contentOf(...), $sent));
        self::assertSame(1, substr_count($prompt, 'You are Nova.'));
    }

    /**
     * A forced snippet the configuration does NOT select still gets its own
     * system message — the containment check must not swallow it.
     */
    #[Test]
    public function aForcedSnippetOutsideTheTagSelectionIsStillAdded(): void
    {
        $extra = new PromptSnippet();
        $extra->setIdentifier('layout-list');
        $extra->setName('List layout');
        $extra->setSnippet('Answer as a list.');

        $sent = $this->runAndCaptureMessages(
            $this->configuration('persona'),
            new RunAugmentation(forcedSnippets: [$extra]),
        );

        $prompt = implode("\n", array_map($this->contentOf(...), $sent));
        self::assertStringContainsString("List layout:\nAnswer as a list.", $prompt);
        self::assertSame(1, substr_count($prompt, 'You are Nova.'));
    }

    /**
     * The context-window estimate must budget for the prompt that goes on the
     * wire. The manager's planner composes the snippets in after this service
     * hands the transcript over, so the loop passes the composed prompt to
     * fit() rather than letting it re-read the configuration entity — which
     * would under-count the wire by the whole snippet block (ADR-107).
     */
    #[Test]
    public function theContextWindowIsToldTheComposedSystemPrompt(): void
    {
        $seen = null;

        $contextWindow = self::createStub(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturnCallback(
            static function (
                array $messages,
                LlmConfiguration $configuration,
                ?ChatOptions $options,
                ?UsageStatistics $lastUsage,
                array $toolSpecs,
                string $injectedText,
                ?string $effectiveSystemPrompt,
            ) use (&$seen): ContextFitResult {
                $seen = $effectiveSystemPrompt;

                return new ContextFitResult([ChatMessage::user('fitted')], false, 0, 1, 10, 1000, false, 1.15);
            },
        );

        $this->runAndCaptureMessages($this->configuration('persona'), null, null, $contextWindow);

        self::assertSame(self::CONFIG_SYSTEM_PROMPT . "\n\n" . self::SNIPPET_BLOCK, $seen);
    }

    /**
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function runAndCaptureMessages(
        LlmConfiguration $configuration,
        ?RunAugmentation $augmentation,
        ?ToolOptions $options = null,
        ?ContextWindowManagerInterface $contextWindow = null,
    ): array {
        $sent = [];

        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(
            static function (array $received) use (&$sent): CompletionResponse {
                $sent = $received;

                return new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1));
            },
        );

        $this->service($manager, $contextWindow)->runLoop(
            [ChatMessage::user('translate this')],
            $configuration,
            ToolExecutionContext::none(),
            null,
            $options,
            null,
            null,
            $augmentation,
        );

        self::assertNotSame([], $sent, 'The loop did not reach the provider call.');

        /** @var list<ChatMessage|array<string, mixed>> $sent */
        return $sent;
    }

    private function service(
        LlmServiceManagerInterface $manager,
        ?ContextWindowManagerInterface $contextWindow = null,
    ): ToolLoopService {
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
            snippetComposer: new PromptSnippetComposer(),
            contextWindow: $contextWindow,
            snippetResolver: $this->resolver(),
        );
    }

    private function novaSnippet(): PromptSnippet
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier('persona-nova');
        $snippet->setName('Nova');
        $snippet->setSnippet('You are Nova.');

        return $snippet;
    }

    private function resolver(): ConfigurationSnippetResolver
    {
        $snippet = $this->novaSnippet();

        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturnCallback(
            static fn(string $tag): array => $tag === 'persona' ? [$snippet] : [],
        );

        return new ConfigurationSnippetResolver($repository, new PromptSnippetComposer());
    }

    /**
     * A LOCAL-trust-zone configuration so the composite gate offers the fake
     * tool at all (see ToolLoopServiceAugmentationTest).
     */
    private function configuration(string $snippetTags): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('snippet-tags');
        $configuration->setLlmModel($model);
        $configuration->setSystemPrompt(self::CONFIG_SYSTEM_PROMPT);
        $configuration->setSnippetTags($snippetTags);

        return $configuration;
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function roleOf(ChatMessage|array $message): string
    {
        if ($message instanceof ChatMessage) {
            return $message->role;
        }

        return is_string($message['role'] ?? null) ? $message['role'] : '';
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
}
