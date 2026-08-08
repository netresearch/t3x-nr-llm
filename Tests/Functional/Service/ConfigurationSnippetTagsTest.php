<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\ConfigurationCallPlanner;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\EmbedCacheKeyBuilder;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\KeyedProviderRegistry;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\MessageShaper;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\RecordingPromptAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * A configuration's `snippet_tags` selection reaches the effective system
 * prompt on every configuration-driven path (#638).
 *
 * This is the end-to-end proof that the snippet library (ADR-031) is no longer
 * inert outside the tool playground: the real {@see PromptSnippetRepository}
 * runs against the real snippet table, and the composed prompt is asserted at
 * the provider boundary — where a live call would read it.
 *
 * The snippets come from `PromptSnippets.csv`; `style-minimalist` is tagged
 * " Style , image" there, which also exercises the normalisation on the
 * snippet side, and `layout-lifestyle` carries `lifestyle`, which the tag
 * `style` must never reach.
 */
#[CoversClass(LlmServiceManager::class)]
#[CoversClass(ConfigurationCallPlanner::class)]
#[CoversClass(ConfigurationSnippetResolver::class)]
final class ConfigurationSnippetTagsTest extends AbstractFunctionalTestCase
{
    private const SYSTEM_PROMPT = 'You are the configured assistant.';

    private const MINIMALIST_BLOCK = "Minimalist style:\nUse a minimalist visual style.";

    private RecordingPromptAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('PromptSnippets.csv');

        $this->adapter = new RecordingPromptAdapter();
    }

    #[Test]
    public function aSelectedTagReachesTheSystemMessageOnTheChatPath(): void
    {
        $this->manager()->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Describe the product.']],
            $this->configuration('style'),
        );

        self::assertSame(
            self::SYSTEM_PROMPT . "\n\n" . self::MINIMALIST_BLOCK,
            $this->adapter->recordedSystemMessage(),
        );
    }

    /**
     * The single-prompt completion path hands the provider a bare string, so
     * the effective system prompt travels in the options; the provider base
     * class turns it into the leading system message
     * ({@see \Netresearch\NrLlm\Provider\AbstractProvider::complete()}).
     */
    #[Test]
    public function aSelectedTagReachesTheOptionsOnTheCompletionPath(): void
    {
        $this->manager()->completeWithConfiguration('Describe the product.', $this->configuration('style'));

        self::assertSame('Describe the product.', $this->adapter->recordedPrompt);
        self::assertSame(
            self::SYSTEM_PROMPT . "\n\n" . self::MINIMALIST_BLOCK,
            $this->adapter->recordedSystemPromptOption(),
        );
    }

    #[Test]
    public function aSelectedTagReachesTheSystemMessageOnTheStreamingPath(): void
    {
        iterator_to_array($this->manager()->streamChatWithConfiguration(
            [['role' => 'user', 'content' => 'Describe the product.']],
            $this->configuration('style'),
        ));

        self::assertSame(
            self::SYSTEM_PROMPT . "\n\n" . self::MINIMALIST_BLOCK,
            $this->adapter->recordedSystemMessage(),
        );
    }

    /**
     * The agent loop's only provider call is
     * `chatWithToolsForConfiguration()`, so covering that entry point covers
     * the loop.
     */
    #[Test]
    public function aSelectedTagReachesTheSystemMessageOnTheAgentLoopPath(): void
    {
        $this->manager()->chatWithToolsForConfiguration(
            [['role' => 'user', 'content' => 'Describe the product.']],
            [ToolSpec::function('noop', 'Does nothing', ['type' => 'object', 'properties' => []])],
            $this->configuration('style'),
            ToolOptions::auto(),
        );

        self::assertSame(
            self::SYSTEM_PROMPT . "\n\n" . self::MINIMALIST_BLOCK,
            $this->adapter->recordedSystemMessage(),
        );
    }

    /**
     * An unknown tag is empty, not an error: the call goes through and the
     * system prompt is exactly the configuration's own.
     */
    #[Test]
    public function anUnknownTagYieldsTheUnchangedSystemPromptAndNoError(): void
    {
        $this->manager()->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Describe the product.']],
            $this->configuration('no_such_tag'),
        );

        self::assertSame(self::SYSTEM_PROMPT, $this->adapter->recordedSystemMessage());
    }

    /**
     * The token boundary of ADR-031, end to end: the tag `style` selects
     * `style-minimalist` and never the snippet tagged `lifestyle`.
     */
    #[Test]
    public function theTagStyleDoesNotReachTheSnippetTaggedLifestyle(): void
    {
        $this->manager()->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Describe the product.']],
            $this->configuration('style'),
        );

        $systemMessage = $this->adapter->recordedSystemMessage();
        self::assertIsString($systemMessage);
        self::assertStringContainsString('Use a minimalist visual style.', $systemMessage);
        self::assertStringNotContainsString('lifestyle magazine spread', $systemMessage);
    }

    /**
     * `style-minimalist` carries both `style` and `image`; selecting both must
     * compose it once.
     */
    #[Test]
    public function aSnippetCarryingTwoSelectedTagsIsComposedOnce(): void
    {
        $this->manager()->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Describe the product.']],
            $this->configuration('style,image'),
        );

        $systemMessage = $this->adapter->recordedSystemMessage();
        self::assertIsString($systemMessage);
        self::assertSame(1, substr_count($systemMessage, 'Use a minimalist visual style.'));
    }

    /**
     * Inactive and deleted snippets stay out — the resolver goes through
     * `findActiveByTag()`, which the fixture's `tone-inactive` and
     * `tone-deleted` rows exercise.
     */
    #[Test]
    public function inactiveAndDeletedSnippetsAreNotComposed(): void
    {
        $this->manager()->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Describe the product.']],
            $this->configuration('tone_of_voice'),
        );

        $systemMessage = $this->adapter->recordedSystemMessage();
        self::assertIsString($systemMessage);
        self::assertStringContainsString('Use a formal, professional tone of voice.', $systemMessage);
        self::assertStringContainsString('Write in a relaxed, casual tone.', $systemMessage);
        self::assertStringNotContainsString('This snippet is inactive.', $systemMessage);
        self::assertStringNotContainsString('This snippet is deleted.', $systemMessage);
    }

    /**
     * The regression guard: a configuration that selects no tags sends the
     * system prompt it always sent.
     */
    #[Test]
    public function aConfigurationWithoutTagsSendsTheUnchangedSystemPrompt(): void
    {
        $this->manager()->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Describe the product.']],
            $this->configuration(''),
        );

        self::assertSame(self::SYSTEM_PROMPT, $this->adapter->recordedSystemMessage());
    }

    /**
     * A manager wired exactly as production wires it — the real snippet
     * repository from the container, the real composer — with only the adapter
     * registry replaced so no HTTP happens.
     */
    private function manager(): LlmServiceManager
    {
        $repository = $this->get(PromptSnippetRepository::class);
        self::assertInstanceOf(PromptSnippetRepository::class, $repository);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($this->adapter);

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['providers' => []]);

        return new LlmServiceManager(
            $adapterRegistry,
            new MiddlewarePipeline([]),
            new KeyedProviderRegistry($extensionConfiguration, new NullLogger()),
            new ConfigurationResolver(),
            new MessageShaper(),
            new EmbedCacheKeyBuilder(self::createStub(CacheManagerInterface::class)),
            null,
            null,
            null,
            new InputGuardrailScreener([]),
            new ConfigurationSnippetResolver($repository, new PromptSnippetComposer()),
        );
    }

    private function configuration(string $snippetTags): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('recording-prompt-fake');
        $provider->setAdapterType('openai');

        $model = new Model();
        $model->setModelId('recording-model');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('snippet-tags');
        $configuration->setLlmModel($model);
        $configuration->setSystemPrompt(self::SYSTEM_PROMPT);
        $configuration->setSnippetTags($snippetTags);

        return $configuration;
    }
}
