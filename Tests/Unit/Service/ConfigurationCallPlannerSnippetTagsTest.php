<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\ConfigurationCallPlanner;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one insertion point that puts a configuration's tag-selected snippets
 * into the effective system prompt (#638).
 *
 * Every configuration-driven entry point on LlmServiceManager — chat,
 * completion, streaming, the agent loop's tool call — builds its options here,
 * so composing in `callOptions()` is what reaches all of them at once.
 */
#[CoversClass(ConfigurationCallPlanner::class)]
final class ConfigurationCallPlannerSnippetTagsTest extends TestCase
{
    private const SYSTEM_PROMPT = 'You are the configured assistant.';

    /**
     * The regression guard: without tags the merged options are the same array
     * they were before the field existed, key for key and byte for byte.
     */
    #[Test]
    public function aConfigurationWithoutTagsProducesByteIdenticalOptions(): void
    {
        $configuration = $this->configuration('');
        $model         = new Model();

        $withoutResolver = $this->planner(null)->callOptions($configuration, $model, []);
        $withResolver    = $this->planner($this->resolver())->callOptions($configuration, $model, []);

        self::assertSame($withoutResolver, $withResolver);
        self::assertSame(self::SYSTEM_PROMPT, $withResolver['system_prompt'] ?? null);
    }

    /**
     * A configuration with neither a system prompt nor tags must not gain a
     * `system_prompt` key — an empty string there would make
     * MessageShaper::applySystemPrompt() skip anyway, but the key itself would
     * still travel to every adapter.
     */
    #[Test]
    public function aConfigurationWithoutPromptAndWithoutTagsGainsNoSystemPromptKey(): void
    {
        $configuration = $this->configuration('');
        $configuration->setSystemPrompt('');

        $options = $this->planner($this->resolver())->callOptions($configuration, new Model(), []);

        self::assertArrayNotHasKey('system_prompt', $options);
    }

    #[Test]
    public function aSelectedTagIsAppendedToTheConfigurationSystemPrompt(): void
    {
        $options = $this->planner($this->resolver())->callOptions($this->configuration('persona'), new Model(), []);

        self::assertSame(
            self::SYSTEM_PROMPT . "\n\n" . "Nova:\nYou are Nova.",
            $options['system_prompt'] ?? null,
        );
    }

    /**
     * Composition happens AFTER the per-call overrides are merged: a caller
     * that overrides the system prompt still gets the configuration's editorial
     * context, because the tags are a property of the configuration, not of the
     * call.
     */
    #[Test]
    public function snippetsAreAppendedToAPerCallSystemPromptOverrideToo(): void
    {
        $options = $this->planner($this->resolver())->callOptions(
            $this->configuration('persona'),
            new Model(),
            ['system_prompt' => 'Per-call prompt.'],
        );

        self::assertSame("Per-call prompt.\n\nNova:\nYou are Nova.", $options['system_prompt'] ?? null);
    }

    /**
     * With tags but no system prompt anywhere, the snippet block becomes the
     * system prompt — this is the case that makes the snippet library reach a
     * production prompt at all.
     */
    #[Test]
    public function withoutASystemPromptTheSnippetBlockBecomesTheSystemPrompt(): void
    {
        $configuration = $this->configuration('persona');
        $configuration->setSystemPrompt('');

        $options = $this->planner($this->resolver())->callOptions($configuration, new Model(), []);

        self::assertSame("Nova:\nYou are Nova.", $options['system_prompt'] ?? null);
    }

    /**
     * An unknown tag is empty, not an error, and leaves the options untouched.
     */
    #[Test]
    public function anUnknownTagLeavesTheOptionsUntouched(): void
    {
        $configuration = $this->configuration('typo_tag');

        self::assertSame(
            $this->planner(null)->callOptions($configuration, new Model(), []),
            $this->planner($this->resolver())->callOptions($configuration, new Model(), []),
        );
    }

    private function planner(?ConfigurationSnippetResolver $resolver): ConfigurationCallPlanner
    {
        return new ConfigurationCallPlanner(
            self::createStub(ProviderAdapterRegistryInterface::class),
            null,
            $resolver,
        );
    }

    private function resolver(): ConfigurationSnippetResolver
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier('persona-nova');
        $snippet->setName('Nova');
        $snippet->setSnippet('You are Nova.');

        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturnCallback(
            static fn(string $tag): array => $tag === 'persona' ? [$snippet] : [],
        );

        return new ConfigurationSnippetResolver($repository, new PromptSnippetComposer());
    }

    private function configuration(string $snippetTags): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('snippet-tags');
        $configuration->setSystemPrompt(self::SYSTEM_PROMPT);
        $configuration->setSnippetTags($snippetTags);

        return $configuration;
    }
}
