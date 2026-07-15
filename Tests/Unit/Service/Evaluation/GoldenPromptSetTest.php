<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GoldenPromptSet::class)]
#[CoversClass(GoldenPrompt::class)]
final class GoldenPromptSetTest extends TestCase
{
    private function prompt(string $id = 'p1'): GoldenPrompt
    {
        return new GoldenPrompt($id, 'Say hi.', [Assertion::contains('hi')]);
    }

    #[Test]
    public function validSetIsConstructed(): void
    {
        $set = new GoldenPromptSet('nr_ai_search.chat', 'Chat', 'A set', [$this->prompt()]);

        self::assertSame('nr_ai_search.chat', $set->identifier);
        self::assertCount(1, $set->prompts);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['NrAiSearch.chat'],
            'leading dot' => ['.chat'],
            'trailing dot' => ['chat.'],
            'spaces' => ['nr ai search'],
        ];
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function invalidIdentifierIsRejected(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000020);
        self::assertInstanceOf(GoldenPromptSet::class, new GoldenPromptSet($identifier, 'Chat', 'A set', [$this->prompt()]));
    }

    #[Test]
    public function emptyPromptsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000022);
        self::assertInstanceOf(GoldenPromptSet::class, new GoldenPromptSet('nr_llm.smoke', 'Smoke', 'desc', []));
    }

    #[Test]
    public function duplicatePromptIdsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000023);
        self::assertInstanceOf(GoldenPromptSet::class, new GoldenPromptSet('nr_llm.smoke', 'Smoke', 'desc', [$this->prompt('dup'), $this->prompt('dup')]));
    }

    #[Test]
    public function promptRequiresAssertionOrReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000012);
        self::assertInstanceOf(GoldenPrompt::class, new GoldenPrompt('p', 'prompt without expectations'));
    }

    #[Test]
    public function promptWithOnlyReferenceIsValid(): void
    {
        $prompt = new GoldenPrompt('p', 'prompt', [], null, 'reference answer');
        self::assertSame('reference answer', $prompt->reference);
    }
}
