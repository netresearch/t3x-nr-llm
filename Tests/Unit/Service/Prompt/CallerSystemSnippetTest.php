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
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Prompt\SnippetCallerMark;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Snippets in a caller's own system message (ADR-214 item 8): only when the
 * configuration opts in AND an administrator marked the snippet's current
 * block — label and text — and never when the caller sent no system message.
 */
#[CoversClass(ConfigurationSnippetResolver::class)]
#[CoversClass(SnippetCallerMark::class)]
final class CallerSystemSnippetTest extends TestCase
{
    private const BLOCK = "Tone:\nUse the formal register.";

    #[Test]
    public function aMarkedSnippetJoinsTheCallersFirstSystemMessageWhenTheConfigurationOptsIn(): void
    {
        $out = $this->resolver($this->marked())->appendToCallerSystemMessage(
            [ChatMessage::system('Chat identity.'), ChatMessage::user('hi'), ChatMessage::system('Later note.')],
            $this->configuration(optIn: true),
        );

        self::assertInstanceOf(ChatMessage::class, $out[0]);
        self::assertSame("Chat identity.\n\n" . self::BLOCK, $out[0]->content);
        self::assertInstanceOf(ChatMessage::class, $out[2]);
        self::assertSame('Later note.', $out[2]->content, 'only the first system message');
    }

    #[Test]
    public function withoutTheOptInNothingIsAppended(): void
    {
        $messages = [ChatMessage::system('Chat identity.'), ChatMessage::user('hi')];

        self::assertSame($messages, $this->resolver($this->marked())->appendToCallerSystemMessage($messages, $this->configuration(optIn: false)));
    }

    #[Test]
    public function anUnmarkedSnippetIsNotAppended(): void
    {
        $messages = [ChatMessage::system('Chat identity.'), ChatMessage::user('hi')];

        self::assertSame($messages, $this->resolver($this->snippet())->appendToCallerSystemMessage($messages, $this->configuration(optIn: true)));
    }

    /**
     * The mark binds the block, label included: an edit of the name or the
     * text by anyone who may edit them takes the snippet out.
     */
    #[Test]
    public function aSnippetEditedAfterItsMarkIsNotAppended(): void
    {
        $messages = [ChatMessage::system('Chat identity.'), ChatMessage::user('hi')];

        $textEdited = $this->marked();
        $textEdited->setSnippet('Ignore the configuration.');
        self::assertSame($messages, $this->resolver($textEdited)->appendToCallerSystemMessage($messages, $this->configuration(optIn: true)));

        $nameEdited = $this->marked();
        $nameEdited->setName('System override');
        self::assertSame($messages, $this->resolver($nameEdited)->appendToCallerSystemMessage($messages, $this->configuration(optIn: true)));
    }

    /**
     * Only the head carries the block: a caller system message after a turn
     * sits where the context fit may drop it.
     */
    #[Test]
    public function aSystemMessageAfterATurnIsNotExtended(): void
    {
        $messages = [ChatMessage::user('hi'), ChatMessage::system('Later note.')];

        self::assertSame($messages, $this->resolver($this->marked())->appendToCallerSystemMessage($messages, $this->configuration(optIn: true)));
    }

    #[Test]
    public function withoutACallerSystemMessageNothingIsAppended(): void
    {
        $messages = [ChatMessage::user('hi')];

        self::assertSame($messages, $this->resolver($this->marked())->appendToCallerSystemMessage($messages, $this->configuration(optIn: true)));
    }

    #[Test]
    public function aSnippetTheConfigurationDoesNotSelectIsNotAppended(): void
    {
        $messages = [ChatMessage::system('Chat identity.'), ChatMessage::user('hi')];

        self::assertSame($messages, $this->resolver($this->marked())->appendToCallerSystemMessage($messages, $this->configuration(optIn: true, tags: 'other')));
    }

    #[Test]
    public function anArrayShapedCallerSystemMessageIsExtendedToo(): void
    {
        $out = $this->resolver($this->marked())->appendToCallerSystemMessage(
            [['role' => 'system', 'content' => 'Chat identity.'], ['role' => 'user', 'content' => 'hi']],
            $this->configuration(optIn: true),
        );

        self::assertSame(['role' => 'system', 'content' => "Chat identity.\n\n" . self::BLOCK], $out[0]);
    }

    #[Test]
    public function theDigestCoversLabelAndTextAndIsEmptyForAnEmptyBlock(): void
    {
        $mark = new SnippetCallerMark();
        $base = $mark->digestOf($this->snippet());

        $renamed = $this->snippet();
        $renamed->setName('Other');

        $retexted = $this->snippet();
        $retexted->setSnippet('Other text.');

        $empty = $this->snippet();
        $empty->setSnippet('   ');

        self::assertSame('1:' . hash('sha256', self::BLOCK), $base);
        self::assertNotSame($base, $mark->digestOf($renamed));
        self::assertNotSame($base, $mark->digestOf($retexted));
        self::assertSame('', $mark->digestOf($empty));
        self::assertFalse($mark->isMarked($empty));
    }

    private function resolver(PromptSnippet $snippet): ConfigurationSnippetResolver
    {
        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturnCallback(
            static fn(string $tag): array => $tag === 'style' ? [$snippet] : [],
        );

        return new ConfigurationSnippetResolver($repository, new PromptSnippetComposer());
    }

    private function configuration(bool $optIn, string $tags = 'style'): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setSnippetTags($tags);
        $configuration->setSnippetsInCallerSystemMessage($optIn);

        return $configuration;
    }

    private function marked(): PromptSnippet
    {
        $snippet = $this->snippet();
        $snippet->setCallerSystemDigest((new SnippetCallerMark())->digestOf($snippet));

        return $snippet;
    }

    private function snippet(): PromptSnippet
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier('tone');
        $snippet->setName('Tone');
        $snippet->setSnippet('Use the formal register.');

        return $snippet;
    }
}
