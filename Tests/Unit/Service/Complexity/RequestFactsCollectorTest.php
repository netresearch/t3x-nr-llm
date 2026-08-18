<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Complexity;

use Netresearch\NrLlm\Domain\Enum\RequestShape;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Complexity\RequestFactsCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * The pre-routing fact set (ADR-174).
 *
 * The first test is the load-bearing one and it is a structural assertion, not
 * a behavioural one: this collector may not be able to see a model. Everything
 * else here pins the individual figures.
 */
#[CoversClass(RequestFactsCollector::class)]
final class RequestFactsCollectorTest extends TestCase
{
    /**
     * The point of the whole class: nothing it can reach knows which model will
     * serve the request. A collaborator that could answer that would let the
     * "model-independent" claim rot silently, because every figure below would
     * still look right.
     */
    #[Test]
    public function theCollectorCanReachNothingThatKnowsAModel(): void
    {
        $reflection = new ReflectionClass(RequestFactsCollector::class);

        $parameterTypes = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameterTypes[] = (string)$parameter->getType();
        }

        self::assertSame(
            [
                'Netresearch\NrLlm\Service\Complexity\MessageInspector',
                'Netresearch\NrLlm\Service\Context\TranscriptEstimator',
            ],
            $parameterTypes,
            'A new collaborator here needs ADR-174 read first: neither of these can name a model, '
            . 'a window or a price, and that is what separates this measurement from the complexity one.',
        );

        // And the entry point takes no configuration, model or fit either.
        $collect = $reflection->getMethod('collect');
        self::assertSame(['array', 'array'], array_map(
            static fn(ReflectionParameter $p): string => (string)$p->getType(),
            $collect->getParameters(),
        ));
    }

    #[Test]
    public function theSystemPromptIsCountedAsAMessageButNotAsATurn(): void
    {
        // Both figures are kept because they answer different questions: how
        // much went on the wire, and how much of it was conversation.
        $facts = (new RequestFactsCollector())->collect(
            [ChatMessage::system('You are terse.'), ChatMessage::user('Why?')],
            [],
        );

        self::assertSame(2, $facts->messageCount);
        self::assertSame(1, $facts->turnCount);
        self::assertSame(RequestShape::SINGLE_TURN->value, $facts->shape);
    }

    #[Test]
    public function severalConversationTurnsMakeItMultiTurn(): void
    {
        $facts = (new RequestFactsCollector())->collect(
            [
                ChatMessage::system('You are terse.'),
                ChatMessage::user('Why?'),
                ChatMessage::assistant('Because.'),
                ChatMessage::user('And?'),
            ],
            [],
        );

        self::assertSame(4, $facts->messageCount);
        self::assertSame(3, $facts->turnCount);
        self::assertSame(RequestShape::MULTI_TURN->value, $facts->shape);
    }

    #[Test]
    public function toolSchemasOnTheWireMakeItToolAssistedAndAreCounted(): void
    {
        $facts = (new RequestFactsCollector())->collect(
            [ChatMessage::user('List the pages.')],
            [
                ['type' => 'function', 'function' => ['name' => 'list_pages']],
                ['type' => 'function', 'function' => ['name' => 'get_page']],
            ],
        );

        self::assertSame(2, $facts->toolCount);
        self::assertSame(RequestShape::TOOL_ASSISTED->value, $facts->shape);
    }

    /**
     * BYTES, not characters — the same unit
     * {@see \Netresearch\NrLlm\Domain\ValueObject\RequestComplexity::$payloadBytes}
     * uses (ADR-121). A CJK payload is the case that separates the two: three
     * characters, nine bytes.
     */
    #[Test]
    public function thePayloadIsMeasuredInBytes(): void
    {
        $facts = (new RequestFactsCollector())->collect([ChatMessage::user('日本語')], []);

        self::assertSame(9, $facts->payloadBytes);
    }

    #[Test]
    public function theTokenEstimateGrowsWithThePayloadAndIsNotZeroForANonEmptySend(): void
    {
        $collector = new RequestFactsCollector();

        $small = $collector->collect([ChatMessage::user('Why?')], []);
        $large = $collector->collect([ChatMessage::user(str_repeat('a lot of prose. ', 200))], []);

        self::assertGreaterThan(0, $small->tokenEstimate);
        self::assertGreaterThan($small->tokenEstimate, $large->tokenEstimate);
    }

    /**
     * The tool schemas go on the wire, so they are part of what the request
     * costs to send — leaving them out would make a tool-heavy request look
     * like a bare one.
     */
    #[Test]
    public function theTokenEstimateIncludesTheToolSchemas(): void
    {
        $collector = new RequestFactsCollector();
        $messages  = [ChatMessage::user('List the pages.')];

        $without = $collector->collect($messages, []);
        $with    = $collector->collect($messages, [
            ['type' => 'function', 'function' => ['name' => 'list_pages', 'description' => 'Lists pages in a tree']],
        ]);

        self::assertGreaterThan($without->tokenEstimate, $with->tokenEstimate);
    }

    /**
     * The manager normalises before it measures, but the legacy array shape
     * still reaches this class through callers that pass a raw transcript.
     */
    #[Test]
    public function legacyArrayShapedMessagesAreReadTheSameWay(): void
    {
        $facts = (new RequestFactsCollector())->collect(
            [
                ['role' => 'system', 'content' => 'You are terse.'],
                ['role' => 'user', 'content' => 'Why?'],
                ['role' => 'tool', 'content' => '{"ok":true}'],
            ],
            [],
        );

        self::assertSame(3, $facts->messageCount);
        self::assertSame(2, $facts->turnCount);
        // A tool RESULT is tool traffic even when the send carries no schemas.
        self::assertSame(RequestShape::TOOL_ASSISTED->value, $facts->shape);
    }
}
