<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\RenderedPrompt;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
class RenderedPromptTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $prompt = new RenderedPrompt(
            systemPrompt: 'You are a translator',
            userPrompt: 'Translate: Hello',
            model: 'gpt-5.2',
            temperature: 0.5,
            maxTokens: 500,
            topP: 0.9,
            metadata: ['key' => 'value'],
        );

        self::assertEquals('You are a translator', $prompt->getSystemPrompt());
        self::assertEquals('Translate: Hello', $prompt->getUserPrompt());
        self::assertEquals('gpt-5.2', $prompt->getModel());
        self::assertEquals(0.5, $prompt->getTemperature());
        self::assertEquals(500, $prompt->getMaxTokens());
        self::assertEquals(0.9, $prompt->getTopP());
        self::assertEquals(['key' => 'value'], $prompt->getMetadata());
    }

    #[Test]
    public function constructorUsesDefaults(): void
    {
        $prompt = new RenderedPrompt(
            systemPrompt: 'System',
            userPrompt: 'User',
        );

        self::assertNull($prompt->getModel());
        self::assertEquals(0.7, $prompt->getTemperature());
        self::assertEquals(1000, $prompt->getMaxTokens());
        self::assertEquals(1.0, $prompt->getTopP());
        self::assertEquals([], $prompt->getMetadata());
    }

    #[Test]
    public function estimateLengthReturnsCombinedLength(): void
    {
        $prompt = new RenderedPrompt(
            systemPrompt: '12345',     // 5 chars
            userPrompt: '1234567890',  // 10 chars
        );

        self::assertEquals(15, $prompt->estimateLength());
    }

    #[Test]
    public function estimateTokensReturnsApproximateCount(): void
    {
        $prompt = new RenderedPrompt(
            systemPrompt: '12345678',   // 8 chars
            userPrompt: '1234567890123456',  // 16 chars = 24 total
        );

        // 24 chars / 4 = 6 tokens
        self::assertEquals(6, $prompt->estimateTokens());
    }

    #[Test]
    public function estimateTokensRoundsCeilingValue(): void
    {
        $prompt = new RenderedPrompt(
            systemPrompt: '123',  // 3 chars
            userPrompt: '12',     // 2 chars = 5 total
        );

        // 5 chars / 4 = 1.25, ceil = 2
        self::assertEquals(2, $prompt->estimateTokens());
    }

    #[Test]
    public function toMessagesReturnsSystemAndUserMessages(): void
    {
        $prompt = new RenderedPrompt(
            systemPrompt: 'You are helpful',
            userPrompt: 'Hello',
        );

        $messages = $prompt->toMessages();

        self::assertCount(2, $messages);
        self::assertEquals('system', $messages[0]['role']);
        self::assertEquals('You are helpful', $messages[0]['content']);
        self::assertEquals('user', $messages[1]['role']);
        self::assertEquals('Hello', $messages[1]['content']);
    }

    #[Test]
    public function toMessagesOmitsEmptySystemPrompt(): void
    {
        $prompt = new RenderedPrompt(
            systemPrompt: '',
            userPrompt: 'Hello',
        );

        $messages = $prompt->toMessages();

        self::assertCount(1, $messages);
        self::assertEquals('user', $messages[0]['role']);
        self::assertEquals('Hello', $messages[0]['content']);
    }

    #[Test]
    public function toMessagesAlwaysIncludesUserMessage(): void
    {
        $prompt = new RenderedPrompt(
            systemPrompt: 'System',
            userPrompt: '',
        );

        $messages = $prompt->toMessages();

        // System + empty user message
        self::assertCount(2, $messages);
        self::assertEquals('user', $messages[1]['role']);
        self::assertEquals('', $messages[1]['content']);
    }
}
