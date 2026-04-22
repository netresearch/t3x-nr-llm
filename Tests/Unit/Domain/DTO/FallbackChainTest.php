<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\DTO;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(FallbackChain::class)]
class FallbackChainTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorUsesEmptyDefault(): void
    {
        $chain = new FallbackChain();

        self::assertSame([], $chain->configurationIdentifiers);
        self::assertTrue($chain->isEmpty());
        self::assertSame(0, $chain->count());
    }

    #[Test]
    public function constructorAcceptsIdentifiers(): void
    {
        $chain = new FallbackChain(['claude-sonnet', 'ollama-local']);

        self::assertSame(['claude-sonnet', 'ollama-local'], $chain->configurationIdentifiers);
        self::assertFalse($chain->isEmpty());
        self::assertSame(2, $chain->count());
    }

    #[Test]
    public function fromArrayCreatesInstanceWithIdentifiers(): void
    {
        $chain = FallbackChain::fromArray([
            'configurationIdentifiers' => ['gpt-4o', 'claude-sonnet'],
        ]);

        self::assertSame(['gpt-4o', 'claude-sonnet'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function fromArrayUsesEmptyListForMissingKey(): void
    {
        $chain = FallbackChain::fromArray([]);

        self::assertSame([], $chain->configurationIdentifiers);
    }

    #[Test]
    public function fromArrayDropsDuplicatesPreservingFirstOccurrenceOrder(): void
    {
        $chain = FallbackChain::fromArray([
            'configurationIdentifiers' => ['a', 'b', 'a', 'c', 'b'],
        ]);

        self::assertSame(['a', 'b', 'c'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function fromArrayDropsEmptyStrings(): void
    {
        $chain = FallbackChain::fromArray([
            'configurationIdentifiers' => ['', 'a', '', 'b'],
        ]);

        self::assertSame(['a', 'b'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function fromJsonCreatesInstanceFromValidJson(): void
    {
        $json = json_encode([
            'configurationIdentifiers' => ['alpha', 'beta'],
        ], JSON_THROW_ON_ERROR);

        $chain = FallbackChain::fromJson($json);

        self::assertSame(['alpha', 'beta'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function fromJsonReturnsEmptyForEmptyString(): void
    {
        $chain = FallbackChain::fromJson('');

        self::assertTrue($chain->isEmpty());
    }

    #[Test]
    public function fromJsonReturnsEmptyForInvalidJson(): void
    {
        $chain = FallbackChain::fromJson('not valid json');

        self::assertTrue($chain->isEmpty());
    }

    #[Test]
    public function fromJsonReturnsEmptyForNonArrayJson(): void
    {
        $chain = FallbackChain::fromJson('"just a string"');

        self::assertTrue($chain->isEmpty());
    }

    #[Test]
    public function fromJsonReturnsEmptyForJsonNull(): void
    {
        $chain = FallbackChain::fromJson('null');

        self::assertTrue($chain->isEmpty());
    }

    #[Test]
    public function toArrayReturnsIdentifiers(): void
    {
        $chain = new FallbackChain(['a', 'b']);

        self::assertSame(
            ['configurationIdentifiers' => ['a', 'b']],
            $chain->toArray(),
        );
    }

    #[Test]
    public function toJsonReturnsValidJsonString(): void
    {
        $chain = new FallbackChain(['x', 'y']);

        $json = $chain->toJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['x', 'y'], $decoded['configurationIdentifiers']);
    }

    #[Test]
    public function jsonSerializeReturnsToArrayResult(): void
    {
        $chain = new FallbackChain(['z']);

        self::assertSame($chain->toArray(), $chain->jsonSerialize());
    }

    #[Test]
    public function jsonEncodeUsesJsonSerialize(): void
    {
        $chain = new FallbackChain(['one', 'two']);

        $json = json_encode($chain, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['one', 'two'], $decoded['configurationIdentifiers']);
    }

    #[Test]
    public function fromJsonAndToJsonRoundTrip(): void
    {
        $original = new FallbackChain(['p', 'q', 'r']);

        $restored = FallbackChain::fromJson($original->toJson());

        self::assertSame($original->toArray(), $restored->toArray());
    }

    #[Test]
    public function containsReturnsTrueForPresentIdentifier(): void
    {
        $chain = new FallbackChain(['foo', 'bar']);

        self::assertTrue($chain->contains('foo'));
        self::assertTrue($chain->contains('bar'));
    }

    #[Test]
    public function containsReturnsFalseForAbsentIdentifier(): void
    {
        $chain = new FallbackChain(['foo']);

        self::assertFalse($chain->contains('bar'));
        self::assertFalse($chain->contains(''));
    }

    #[Test]
    public function withLinkAppendsIdentifier(): void
    {
        $chain = new FallbackChain(['first']);

        $updated = $chain->withLink('second');

        self::assertSame(['first', 'second'], $updated->configurationIdentifiers);
        self::assertSame(['first'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function withLinkReturnsSameInstanceForDuplicate(): void
    {
        $chain = new FallbackChain(['first']);

        $updated = $chain->withLink('first');

        self::assertSame($chain, $updated);
    }

    #[Test]
    public function withLinkReturnsSameInstanceForEmptyString(): void
    {
        $chain = new FallbackChain(['first']);

        $updated = $chain->withLink('');

        self::assertSame($chain, $updated);
    }

    #[Test]
    public function withLinkPreservesOrderOfExistingLinks(): void
    {
        $chain = (new FallbackChain())
            ->withLink('a')
            ->withLink('b')
            ->withLink('c');

        self::assertSame(['a', 'b', 'c'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function withoutRemovesIdentifier(): void
    {
        $chain = new FallbackChain(['a', 'b', 'c']);

        $updated = $chain->without('b');

        self::assertSame(['a', 'c'], $updated->configurationIdentifiers);
        self::assertSame(['a', 'b', 'c'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function withoutReturnsSameInstanceIfIdentifierNotPresent(): void
    {
        $chain = new FallbackChain(['a']);

        $updated = $chain->without('not-there');

        self::assertSame($chain, $updated);
    }

    #[Test]
    public function withoutReturnsSameInstanceForEmptyString(): void
    {
        $chain = new FallbackChain(['a']);

        $updated = $chain->without('');

        self::assertSame($chain, $updated);
    }

    #[Test]
    public function constructorDoesNotSanitizeDuplicatesDirectly(): void
    {
        // The constructor trusts its input (readonly property).
        // Sanitization happens on fromArray/fromJson entry points only,
        // matching other DTOs in the codebase. This test documents that.
        $chain = new FallbackChain(['a', 'a']);

        self::assertSame(['a', 'a'], $chain->configurationIdentifiers);
    }

    // ──────────────────────────────────────────────
    // Normalisation (trim + lowercase) on sanitize entry points
    // ──────────────────────────────────────────────

    #[Test]
    public function sanitizeNormalisesCasingAndWhitespace(): void
    {
        $chain = FallbackChain::fromArray([
            'configurationIdentifiers' => ['  CLAUDE  ', 'ollama', 'Claude'],
        ]);

        // trimmed + lowercased; duplicate after normalisation dropped
        self::assertSame(['claude', 'ollama'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function sanitizeDropsNonStringEntries(): void
    {
        // Malformed JSON where the list contains arrays/objects/null/ints
        // must not throw "Illegal offset type" during sanitize.
        $chain = FallbackChain::fromArray([
            'configurationIdentifiers' => [
                ['nested' => 'array'],
                null,
                42,
                true,
                'valid',
                (object)['x' => 1],
            ],
        ]);

        self::assertSame(['valid'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function fromArrayReturnsEmptyWhenConfigurationIdentifiersIsNotAnArray(): void
    {
        $chain = FallbackChain::fromArray(['configurationIdentifiers' => 'oops']);

        self::assertTrue($chain->isEmpty());
    }

    #[Test]
    public function containsIsCaseInsensitiveAndTrimsInput(): void
    {
        $chain = new FallbackChain(['claude', 'ollama']);

        self::assertTrue($chain->contains('CLAUDE'));
        self::assertTrue($chain->contains('  Claude  '));
        self::assertTrue($chain->contains('ollama'));
    }

    #[Test]
    public function withLinkNormalisesInputBeforeAppending(): void
    {
        $chain = (new FallbackChain(['claude']))->withLink('  OLLAMA  ');

        self::assertSame(['claude', 'ollama'], $chain->configurationIdentifiers);
    }

    #[Test]
    public function withLinkRejectsWhitespaceOnlyInput(): void
    {
        $chain = new FallbackChain(['claude']);

        $updated = $chain->withLink("   \t\n  ");

        self::assertSame($chain, $updated);
    }

    #[Test]
    public function withoutNormalisesInputBeforeRemoving(): void
    {
        $chain = new FallbackChain(['claude', 'ollama']);

        $updated = $chain->without('  OLLAMA  ');

        self::assertSame(['claude'], $updated->configurationIdentifiers);
    }
}
