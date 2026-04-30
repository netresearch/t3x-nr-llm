<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\DTO;

use Netresearch\NrLlm\Domain\DTO\CapabilitySet;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapabilitySet::class)]
final class CapabilitySetTest extends TestCase
{
    #[Test]
    public function emptyConstructorYieldsEmptySet(): void
    {
        $set = new CapabilitySet();

        self::assertTrue($set->isEmpty());
        self::assertSame(0, $set->count());
        self::assertSame('', $set->toCsv());
        self::assertSame([], $set->toStringList());
    }

    #[Test]
    public function fromCsvParsesKnownTokensAndDropsUnknown(): void
    {
        // Defensive against schema drift: an old DB row carrying a
        // capability that has since been removed from the enum
        // should not crash the set — drop it silently.
        $set = CapabilitySet::fromCsv('chat,vision,obsolete_capability,tools');

        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::VISION,
            ModelCapability::TOOLS,
        ], $set->capabilities);
    }

    #[Test]
    public function fromCsvTrimsWhitespaceAndDeduplicates(): void
    {
        // Manually-edited DB rows often have stray spaces around
        // tokens; double entries can sneak in via a botched edit.
        $set = CapabilitySet::fromCsv('chat, vision,   chat,tools');

        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::VISION,
            ModelCapability::TOOLS,
        ], $set->capabilities);
    }

    #[Test]
    public function fromCsvEmptyStringYieldsEmptySet(): void
    {
        self::assertTrue(CapabilitySet::fromCsv('')->isEmpty());
    }

    #[Test]
    public function fromArrayAcceptsBothEnumsAndStrings(): void
    {
        $set = CapabilitySet::fromArray([
            ModelCapability::CHAT,
            'vision',
            'tools',
            ModelCapability::CHAT, // dedupe across mixed-input forms
        ]);

        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::VISION,
            ModelCapability::TOOLS,
        ], $set->capabilities);
    }

    #[Test]
    public function fromArrayDropsNonStringNonEnumTokens(): void
    {
        // A misbehaving caller could hand us numbers, nulls, arrays;
        // be permissive and ignore rather than throw.
        $set = CapabilitySet::fromArray(['chat', 42, null, ['nested'], 'vision']);

        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::VISION,
        ], $set->capabilities);
    }

    #[Test]
    public function toCsvRoundTripsThroughFromCsv(): void
    {
        $set = CapabilitySet::fromCsv('chat,vision,tools');

        self::assertSame('chat,vision,tools', $set->toCsv());
        self::assertEquals($set, CapabilitySet::fromCsv($set->toCsv()));
    }

    #[Test]
    public function hasAcceptsBothEnumAndString(): void
    {
        $set = CapabilitySet::fromArray([ModelCapability::CHAT, ModelCapability::VISION]);

        self::assertTrue($set->has(ModelCapability::CHAT));
        self::assertTrue($set->has('chat'));
        self::assertFalse($set->has(ModelCapability::TOOLS));
        self::assertFalse($set->has('tools'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownStringTokens(): void
    {
        // Defensive: an unknown string is "not in the set" rather
        // than an error. Callers that need strict validation should
        // check `ModelCapability::isValid()` themselves.
        $set = CapabilitySet::fromArray([ModelCapability::CHAT]);

        self::assertFalse($set->has('not-a-capability'));
    }

    #[Test]
    public function withAddsCapabilityIdempotently(): void
    {
        $original = CapabilitySet::fromArray([ModelCapability::CHAT]);
        $modified = $original->with(ModelCapability::VISION);

        self::assertSame([ModelCapability::CHAT], $original->capabilities);
        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::VISION,
        ], $modified->capabilities);

        // Adding again is a no-op (returns same instance for invariance).
        self::assertSame($modified, $modified->with(ModelCapability::VISION));
    }

    #[Test]
    public function withAcceptsStringFormAndIgnoresUnknown(): void
    {
        $set = (new CapabilitySet())->with('chat')->with('not-a-capability');

        self::assertSame([ModelCapability::CHAT], $set->capabilities);
    }

    #[Test]
    public function withoutRemovesCapability(): void
    {
        $original = CapabilitySet::fromArray([
            ModelCapability::CHAT,
            ModelCapability::VISION,
            ModelCapability::TOOLS,
        ]);
        $modified = $original->without(ModelCapability::VISION);

        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::VISION,
            ModelCapability::TOOLS,
        ], $original->capabilities);
        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::TOOLS,
        ], $modified->capabilities);
    }

    #[Test]
    public function withoutIsNoOpWhenCapabilityAbsent(): void
    {
        $set = CapabilitySet::fromArray([ModelCapability::CHAT]);

        self::assertSame($set, $set->without(ModelCapability::VISION));
        self::assertSame($set, $set->without('not-a-capability'));
    }

    #[Test]
    public function jsonSerializeReturnsStringList(): void
    {
        $set = CapabilitySet::fromArray([
            ModelCapability::CHAT,
            ModelCapability::VISION,
        ]);

        self::assertSame(['chat', 'vision'], $set->jsonSerialize());
        self::assertSame('["chat","vision"]', json_encode($set, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function nativeCountWorksThroughCountableInterface(): void
    {
        // Implementing \Countable lets callers use `count($set)` and
        // works around PHP 8.x's "Countable behaviour change" warnings
        // that would otherwise treat a non-Countable object as 1.
        $set = CapabilitySet::fromArray([
            ModelCapability::CHAT,
            ModelCapability::VISION,
        ]);

        self::assertCount(2, $set);
        self::assertCount(2, $set);
    }

    #[Test]
    public function hasTrimsStringInputs(): void
    {
        // Same normalisation as fromArray() so the string entry
        // points behave consistently — `' chat'` resolves to
        // `ModelCapability::CHAT`, not "unknown".
        $set = CapabilitySet::fromArray([ModelCapability::CHAT]);

        self::assertTrue($set->has(' chat'));
        self::assertTrue($set->has("chat\n"));
    }

    #[Test]
    public function withTrimsStringInputs(): void
    {
        $set = (new CapabilitySet())->with(' chat ');

        self::assertTrue($set->has(ModelCapability::CHAT));
    }

    #[Test]
    public function withoutTrimsStringInputs(): void
    {
        $set = CapabilitySet::fromArray([ModelCapability::CHAT, ModelCapability::TOOLS]);

        $reduced = $set->without(' chat');

        self::assertFalse($reduced->has(ModelCapability::CHAT));
        self::assertTrue($reduced->has(ModelCapability::TOOLS));
    }

    #[Test]
    public function publicConstructorTrustsCallerInputForDuplicates(): void
    {
        // Documented contract: the public constructor TRUSTS its
        // input. Use `fromArray()` / `fromCsv()` for arbitrary input.
        // This test pins the contract so an accidental "let's add
        // dedup to the constructor" change forces a deliberate
        // decision.
        $set = new CapabilitySet([
            ModelCapability::CHAT,
            ModelCapability::CHAT,
        ]);

        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::CHAT,
        ], $set->capabilities);
    }
}
