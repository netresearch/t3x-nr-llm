<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Prompt;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for PromptSnippetComposer.
 */
#[CoversClass(PromptSnippetComposer::class)]
final class PromptSnippetComposerTest extends AbstractUnitTestCase
{
    private PromptSnippetComposer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new PromptSnippetComposer();
    }

    private function createSnippet(string $text): PromptSnippet
    {
        $snippet = new PromptSnippet();
        $snippet->setSnippet($text);

        return $snippet;
    }

    #[Test]
    public function composeSectionsBuildsLabeledBlocksJoinedByBlankLines(): void
    {
        $result = $this->subject->composeSections([
            'TARGET AUDIENCE' => $this->createSnippet('Marketing professionals.'),
            'TONE OF VOICE' => $this->createSnippet('Friendly and concise.'),
        ]);

        self::assertSame(
            "TARGET AUDIENCE:\nMarketing professionals.\n\nTONE OF VOICE:\nFriendly and concise.",
            $result,
        );
    }

    #[Test]
    public function composeSectionsPreservesInputOrder(): void
    {
        $result = $this->subject->composeSections([
            'ZULU' => $this->createSnippet('Last label first.'),
            'ALPHA' => $this->createSnippet('First label last.'),
        ]);

        self::assertSame(
            "ZULU:\nLast label first.\n\nALPHA:\nFirst label last.",
            $result,
        );
    }

    #[Test]
    public function composeSectionsSkipsNullEntries(): void
    {
        $result = $this->subject->composeSections([
            'TARGET AUDIENCE' => $this->createSnippet('Marketing professionals.'),
            'TONE OF VOICE' => null,
            'IMAGE STYLE' => $this->createSnippet('Minimalist.'),
        ]);

        self::assertSame(
            "TARGET AUDIENCE:\nMarketing professionals.\n\nIMAGE STYLE:\nMinimalist.",
            $result,
        );
    }

    #[Test]
    public function composeSectionsSkipsSnippetsWithEmptyText(): void
    {
        $result = $this->subject->composeSections([
            'EMPTY' => $this->createSnippet(''),
            'WHITESPACE' => $this->createSnippet("  \n\t "),
            'PERSONA' => $this->createSnippet('You are a friendly expert.'),
        ]);

        self::assertSame("PERSONA:\nYou are a friendly expert.", $result);
    }

    #[Test]
    public function composeSectionsTrimsSnippetText(): void
    {
        $result = $this->subject->composeSections([
            'PERSONA' => $this->createSnippet("\n  You are a friendly expert.  \n"),
        ]);

        self::assertSame("PERSONA:\nYou are a friendly expert.", $result);
    }

    #[Test]
    public function composeSectionsReturnsEmptyStringForEmptyMap(): void
    {
        self::assertSame('', $this->subject->composeSections([]));
    }

    #[Test]
    public function composeSectionsReturnsEmptyStringWhenAllEntriesAreNull(): void
    {
        $result = $this->subject->composeSections([
            'TARGET AUDIENCE' => null,
            'TONE OF VOICE' => null,
        ]);

        self::assertSame('', $result);
    }

    #[Test]
    public function composeSectionsKeepsMultilineSnippetTextIntact(): void
    {
        $result = $this->subject->composeSections([
            'LAYOUT' => $this->createSnippet("Use two columns.\nHeadline on top."),
        ]);

        self::assertSame("LAYOUT:\nUse two columns.\nHeadline on top.", $result);
    }
}
