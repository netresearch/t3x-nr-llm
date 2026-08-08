<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Prompt;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The composition rule for a configuration's tag-selected snippets (#638).
 *
 * The repository is stubbed here with an in-memory tag index; the exact-token
 * matching it performs has its own coverage in PromptSnippetRepositoryTest
 * (functional) and PromptSnippetTest (unit).
 */
#[CoversClass(ConfigurationSnippetResolver::class)]
final class ConfigurationSnippetResolverTest extends TestCase
{
    private const SYSTEM_PROMPT = 'You are the configured assistant.';

    /**
     * A configuration without tags must be byte-identical to the state before
     * the field existed — no separator, no trailing newline, nothing.
     */
    #[Test]
    public function aConfigurationWithoutTagsLeavesTheSystemPromptByteIdentical(): void
    {
        $resolver = $this->resolver(['persona' => [$this->snippet('persona-nova', 'Nova', 'You are Nova.')]]);

        self::assertSame(
            self::SYSTEM_PROMPT,
            $resolver->appendTo(self::SYSTEM_PROMPT, $this->configuration('')),
        );
    }

    /**
     * An empty system prompt with no tags stays empty — the caller must not
     * end up adding a system message that did not exist before.
     */
    #[Test]
    public function anEmptySystemPromptWithoutTagsStaysEmpty(): void
    {
        $resolver = $this->resolver([]);

        self::assertSame('', $resolver->appendTo('', $this->configuration('')));
    }

    #[Test]
    public function aSelectedTagAppendsItsSnippetAsALabelledBlock(): void
    {
        $resolver = $this->resolver(['persona' => [$this->snippet('persona-nova', 'Nova', 'You are Nova.')]]);

        self::assertSame(
            self::SYSTEM_PROMPT . "\n\n" . "Nova:\nYou are Nova.",
            $resolver->appendTo(self::SYSTEM_PROMPT, $this->configuration('persona')),
        );
    }

    /**
     * Without a system prompt the snippet block IS the system prompt — no
     * leading blank line.
     */
    #[Test]
    public function withoutASystemPromptTheSnippetBlockBecomesTheWholePrompt(): void
    {
        $resolver = $this->resolver(['persona' => [$this->snippet('persona-nova', 'Nova', 'You are Nova.')]]);

        self::assertSame(
            "Nova:\nYou are Nova.",
            $resolver->appendTo('', $this->configuration('persona')),
        );
    }

    /**
     * An unknown tag yields nothing and throws nothing — ADR-031's free-tag
     * model has no referential integrity by design, so a typo must degrade to
     * "no snippets", never to an error.
     */
    #[Test]
    public function anUnknownTagYieldsAnUnchangedPromptRatherThanAnError(): void
    {
        $resolver = $this->resolver(['persona' => [$this->snippet('persona-nova', 'Nova', 'You are Nova.')]]);

        self::assertSame(
            self::SYSTEM_PROMPT,
            $resolver->appendTo(self::SYSTEM_PROMPT, $this->configuration('typo_tag')),
        );
    }

    /**
     * The dedup case: findActiveByTag() loads all active snippets and filters
     * in PHP, so one snippet carrying two selected tags is returned by both
     * lookups. It must reach the prompt once.
     */
    #[Test]
    public function aSnippetCarryingTwoSelectedTagsIsComposedOnce(): void
    {
        $shared   = $this->snippet('style-minimalist', 'Minimalist', 'Use a minimalist visual style.');
        $resolver = $this->resolver([
            'style' => [$shared],
            'image' => [$shared],
        ]);

        $composed = $resolver->appendTo('', $this->configuration('style,image'));

        self::assertSame("Minimalist:\nUse a minimalist visual style.", $composed);
        self::assertSame(1, substr_count($composed, 'Minimalist:'));
    }

    /**
     * Dedup is by identifier, not by object: two rehydrations of the same
     * record (as an Extbase query can hand out across two calls) are one
     * snippet.
     */
    #[Test]
    public function twoInstancesOfTheSameRecordAreDedupedByIdentifier(): void
    {
        $resolver = $this->resolver([
            'style' => [$this->snippet('style-minimalist', 'Minimalist', 'Use a minimalist visual style.')],
            'image' => [$this->snippet('style-minimalist', 'Minimalist', 'Use a minimalist visual style.')],
        ]);

        $composed = $resolver->appendTo('', $this->configuration('style,image'));

        self::assertSame(1, substr_count($composed, 'Minimalist:'));
    }

    /**
     * Distinct snippets sharing a display name both survive — the composer
     * keys its sections by label, so they are composed one at a time.
     */
    #[Test]
    public function twoDistinctSnippetsSharingANameAreBothComposed(): void
    {
        $resolver = $this->resolver([
            'persona' => [
                $this->snippet('persona-a', 'Persona', 'First fragment.'),
                $this->snippet('persona-b', 'Persona', 'Second fragment.'),
            ],
        ]);

        self::assertSame(
            "Persona:\nFirst fragment.\n\nPersona:\nSecond fragment.",
            $resolver->appendTo('', $this->configuration('persona')),
        );
    }

    /**
     * Selection order decides block order; the repository's own order decides
     * within a tag.
     */
    #[Test]
    public function blocksFollowTheTagSelectionOrder(): void
    {
        $resolver = $this->resolver([
            'persona' => [$this->snippet('persona-nova', 'Nova', 'You are Nova.')],
            'layout'  => [$this->snippet('layout-list', 'List layout', 'Answer as a list.')],
        ]);

        self::assertSame(
            "List layout:\nAnswer as a list.\n\nNova:\nYou are Nova.",
            $resolver->appendTo('', $this->configuration('layout,persona')),
        );
    }

    /**
     * A snippet whose text is empty contributes no block and therefore no
     * separator either — the composer skips it and the prompt stays as it was.
     */
    #[Test]
    public function aSnippetWithEmptyTextAddsNothing(): void
    {
        $resolver = $this->resolver(['persona' => [$this->snippet('persona-blank', 'Blank', '   ')]]);

        self::assertSame(
            self::SYSTEM_PROMPT,
            $resolver->appendTo(self::SYSTEM_PROMPT, $this->configuration('persona')),
        );
    }

    /**
     * The configuration normalizes its own tags, so a capitalised or padded
     * selection reaches the repository lowercased and trimmed.
     */
    #[Test]
    public function theSelectedTagsReachTheRepositoryNormalized(): void
    {
        $asked = [];

        $repository = $this->createMock(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturnCallback(
            static function (string $tag) use (&$asked): array {
                $asked[] = $tag;

                return [];
            },
        );

        $resolver = new ConfigurationSnippetResolver($repository, new PromptSnippetComposer());
        $resolver->appendTo('', $this->configuration(' Persona , LAYOUT '));

        self::assertSame(['persona', 'layout'], $asked);
    }

    /**
     * @param array<string, list<PromptSnippet>> $byTag
     */
    private function resolver(array $byTag): ConfigurationSnippetResolver
    {
        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturnCallback(
            static fn(string $tag): array => $byTag[$tag] ?? [],
        );

        return new ConfigurationSnippetResolver($repository, new PromptSnippetComposer());
    }

    private function configuration(string $snippetTags): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('snippet-tags');
        $configuration->setSnippetTags($snippetTags);

        return $configuration;
    }

    private function snippet(string $identifier, string $name, string $text): PromptSnippet
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier($identifier);
        $snippet->setName($name);
        $snippet->setSnippet($text);

        return $snippet;
    }
}
