<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\DTO;

use Netresearch\NrLlm\Domain\DTO\ProviderOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ProviderOptions::class)]
final class ProviderOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function emptyConstructorYieldsEmptyOptions(): void
    {
        $options = new ProviderOptions();

        self::assertTrue($options->isEmpty());
        self::assertNull($options->proxy);
        self::assertSame([], $options->customHeaders);
        self::assertSame([], $options->extra);
        self::assertSame([], $options->toArray());
        self::assertSame('[]', $options->toJson());
    }

    #[Test]
    public function fromArrayExtractsWellKnownTypedFields(): void
    {
        $options = ProviderOptions::fromArray([
            'proxy' => 'http://proxy.example.com:3128',
            'customHeaders' => [
                'X-Org' => 'acme',
                'X-Env' => 'prod',
            ],
        ]);

        self::assertSame('http://proxy.example.com:3128', $options->proxy);
        self::assertSame(['X-Org' => 'acme', 'X-Env' => 'prod'], $options->customHeaders);
        self::assertSame([], $options->extra);
        self::assertFalse($options->isEmpty());
    }

    #[Test]
    public function fromArrayFunnelsUnknownKeysIntoExtra(): void
    {
        // Pre-existing test fixtures used keys like `custom_param` and
        // `timeout` (transport-level overrides). Those must round-trip
        // unchanged through the DTO so a DB row that was written by an
        // older controller path is preserved verbatim.
        $options = ProviderOptions::fromArray([
            'proxy' => 'http://proxy.example.com',
            'custom_param' => 'custom_value',
            'retry_backoff_ms' => 250,
            'feature_flags' => ['fast_mode', 'beta_endpoints'],
        ]);

        self::assertSame('http://proxy.example.com', $options->proxy);
        self::assertSame([
            'custom_param' => 'custom_value',
            'retry_backoff_ms' => 250,
            'feature_flags' => ['fast_mode', 'beta_endpoints'],
        ], $options->extra);
    }

    #[Test]
    public function fromArrayDropsTypeMismatchedWellKnownFields(): void
    {
        // Defensive: a hand-edited DB row with `proxy: 42` (instead of
        // a string URL) must not crash — silently drop and keep the
        // rest of the row.
        $options = ProviderOptions::fromArray([
            'proxy' => 42,
            'customHeaders' => 'not-a-map',
            'extra_thing' => 'kept',
        ]);

        self::assertNull($options->proxy);
        self::assertSame([], $options->customHeaders);
        self::assertSame(['extra_thing' => 'kept'], $options->extra);
    }

    #[Test]
    public function fromArrayDropsNonStringHeaderKeysAndValues(): void
    {
        // `customHeaders` must always be `array<string, string>` so
        // adapters can splice it into HTTP request headers without
        // type-checking every entry.
        $options = ProviderOptions::fromArray([
            'customHeaders' => [
                'X-Org' => 'acme',
                'X-Numeric-Value' => 42, // value not a string -> dropped
                123 => 'numeric-key',     // key not a string -> dropped
                'X-Env' => 'prod',
            ],
        ]);

        self::assertSame(['X-Org' => 'acme', 'X-Env' => 'prod'], $options->customHeaders);
    }

    #[Test]
    public function fromArrayDropsEmptyProxyString(): void
    {
        // An empty `proxy` is "not configured", same as null. This
        // matches how `Provider::$options` is hydrated from TCA when
        // the field has been cleared but not deleted.
        $options = ProviderOptions::fromArray(['proxy' => '']);

        self::assertNull($options->proxy);
        self::assertTrue($options->isEmpty());
    }

    #[Test]
    public function fromJsonRoundtripsThroughToJson(): void
    {
        $original = new ProviderOptions(
            proxy: 'http://proxy.example.com',
            customHeaders: ['X-Org' => 'acme'],
            extra: ['custom_param' => 'value'],
        );

        $rebuilt = ProviderOptions::fromJson($original->toJson());

        self::assertSame($original->proxy, $rebuilt->proxy);
        self::assertSame($original->customHeaders, $rebuilt->customHeaders);
        self::assertSame($original->extra, $rebuilt->extra);
    }

    #[Test]
    public function fromJsonHandlesEmptyAndInvalidInput(): void
    {
        // Empty string is the entity's "nothing configured" sentinel
        // (Extbase initialises `Provider::$options` to ''). Invalid
        // JSON and non-object JSON must not throw — yield empty.
        self::assertTrue(ProviderOptions::fromJson('')->isEmpty());
        self::assertTrue(ProviderOptions::fromJson('not valid json')->isEmpty());
        self::assertTrue(ProviderOptions::fromJson('"just a string"')->isEmpty());
        self::assertTrue(ProviderOptions::fromJson('[1,2,3]')->isEmpty());
    }

    #[Test]
    public function toArrayOmitsEmptyWellKnownFields(): void
    {
        // An empty DTO must round-trip to `[]`, not
        // `['proxy' => null, 'customHeaders' => []]` — otherwise the
        // persisted JSON gets noisier on every save cycle.
        self::assertSame([], (new ProviderOptions())->toArray());

        $partialOptions = new ProviderOptions(
            extra: ['custom_param' => 'v'],
        );

        self::assertSame(['custom_param' => 'v'], $partialOptions->toArray());
    }

    #[Test]
    public function toArrayPlacesWellKnownFieldsAfterExtraToFavourTypedSourceOfTruth(): void
    {
        // If `$extra` carries a key that shadows a typed field
        // (only possible if a caller bypassed `withExtra()` — which
        // refuses well-known keys), the typed field wins on output.
        // This keeps `fromArray(toArray($x)) == $x` even after an
        // adversarial constructor call.
        $options = new ProviderOptions(
            proxy: 'http://typed-proxy.example.com',
            extra: ['proxy' => 'http://shadow.example.com'],
        );

        $serialised = $options->toArray();

        self::assertSame('http://typed-proxy.example.com', $serialised['proxy']);
    }

    #[Test]
    public function getReturnsTypedFieldsAndExtras(): void
    {
        $options = new ProviderOptions(
            proxy: 'http://proxy.example.com',
            customHeaders: ['X-Org' => 'acme'],
            extra: ['custom_param' => 'v'],
        );

        self::assertSame('http://proxy.example.com', $options->get('proxy'));
        self::assertSame(['X-Org' => 'acme'], $options->get('customHeaders'));
        self::assertSame('v', $options->get('custom_param'));
        self::assertNull($options->get('absent'));
        self::assertSame('fallback', $options->get('absent', 'fallback'));
    }

    #[Test]
    public function hasChecksWellKnownAndExtraKeys(): void
    {
        $options = new ProviderOptions(
            proxy: 'http://proxy.example.com',
            extra: ['custom_param' => 'v', 'null_extra' => null],
        );

        self::assertTrue($options->has('proxy'));
        self::assertFalse($options->has('customHeaders'));
        self::assertTrue($options->has('custom_param'));
        // `null` in $extra still counts as "present" — array_key_exists,
        // not isset — matching the read semantics of getOptionsArray().
        self::assertTrue($options->has('null_extra'));
        self::assertFalse($options->has('absent'));
    }

    #[Test]
    public function withProxyReturnsNewInstance(): void
    {
        $original = new ProviderOptions();
        $modified = $original->withProxy('http://proxy.example.com');

        self::assertNull($original->proxy);
        self::assertSame('http://proxy.example.com', $modified->proxy);

        // Empty string clears, matching `fromArray()` normalisation.
        self::assertNull($modified->withProxy('')->proxy);
        self::assertNull($modified->withProxy(null)->proxy);
    }

    #[Test]
    public function withCustomHeadersReplacesAndSanitises(): void
    {
        $options = (new ProviderOptions())->withCustomHeaders([
            'X-Org' => 'acme',
        ]);

        self::assertSame(['X-Org' => 'acme'], $options->customHeaders);

        // Non-string values must be dropped — exercise via fromArray()
        // since the typed signature on withCustomHeaders() forbids
        // non-string values at the type level.
        $sanitised = ProviderOptions::fromArray([
            'customHeaders' => ['X-Org' => 'acme', 'X-Drop' => 42],
        ]);
        self::assertSame(['X-Org' => 'acme'], $sanitised->customHeaders);
    }

    #[Test]
    public function withExtraSetsArbitraryKeyButRefusesWellKnownNames(): void
    {
        $base = new ProviderOptions(proxy: 'http://typed.example.com');

        $augmented = $base->withExtra('custom_param', 'v');
        self::assertSame('v', $augmented->extra['custom_param']);
        self::assertSame('http://typed.example.com', $augmented->proxy);

        // Refuses to overwrite typed fields via the extra bag.
        $rejected = $augmented->withExtra('proxy', 'http://injected.example.com');
        self::assertSame($augmented, $rejected);

        $rejectedHeaders = $augmented->withExtra('customHeaders', ['X' => 'y']);
        self::assertSame($augmented, $rejectedHeaders);
    }

    #[Test]
    public function realWorldFixtureRoundTripsLossless(): void
    {
        // Pinned against the `proxy` + `timeout` shape used in
        // `Tests/Unit/Domain/Model/ProviderTest::getOptionsArrayDecodesValidJson`,
        // and the `custom_header` shape advertised by the TCA
        // placeholder, to ensure no existing DB row is mis-mapped
        // by the new accessor.
        $persisted = '{"proxy":"http://proxy.example.com","timeout":30,"custom_header":"value"}';

        $options = ProviderOptions::fromJson($persisted);

        self::assertSame('http://proxy.example.com', $options->proxy);
        self::assertSame(30, $options->extra['timeout']);
        self::assertSame('value', $options->extra['custom_header']);

        // Roundtrip preserves every key.
        $rebuilt = ProviderOptions::fromJson($options->toJson());
        self::assertSame($options->proxy, $rebuilt->proxy);
        self::assertSame($options->extra, $rebuilt->extra);
    }

    #[Test]
    public function jsonSerializeMatchesToArray(): void
    {
        $options = new ProviderOptions(
            proxy: 'http://proxy.example.com',
            customHeaders: ['X-Org' => 'acme'],
            extra: ['custom_param' => 'v'],
        );

        self::assertSame($options->toArray(), $options->jsonSerialize());
        self::assertSame(
            json_encode($options->toArray(), JSON_THROW_ON_ERROR),
            json_encode($options, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function publicConstructorTrustsCallerInputForUnsanitisedHeaders(): void
    {
        // Documented contract: like sibling DTOs, the public
        // constructor TRUSTS its input. Use `fromArray()` /
        // `withCustomHeaders()` for arbitrary input. This test pins
        // the contract so an accidental "let's add validation to the
        // constructor" change forces a deliberate decision.
        $options = new ProviderOptions(
            customHeaders: ['X-Trusted' => 'yes'],
        );

        self::assertSame(['X-Trusted' => 'yes'], $options->customHeaders);
    }
}
