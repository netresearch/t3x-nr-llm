<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;
use Netresearch\NrLlm\Specialized\Option\DeepLOptions;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Option\TranscriptionOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The caller identity (ADR-177) across the array round trip of the four
 * specialized option classes.
 *
 * These classes inherit `withCallerSource()` from {@see AbstractOptions}, so a
 * consumer can annotate them and the call compiles. Before #844 the annotation
 * was then dropped by `fromArray()`, which reads only its own keys — the worst
 * shape a declaration can have, because it looks supported and is not.
 *
 * The two directions are deliberately asymmetric, and both are asserted here:
 * `fromArray()` reads the consumer's own input and MUST carry the identity,
 * while `toArray()` builds the provider payload and MUST NOT — the identity is
 * attribution metadata, exactly like `configuration` and the budget fields that
 * are absent there for the same reason.
 */
#[CoversClass(ImageGenerationOptions::class)]
#[CoversClass(SpeechSynthesisOptions::class)]
#[CoversClass(TranscriptionOptions::class)]
#[CoversClass(DeepLOptions::class)]
final class CallerSourceRoundTripTest extends TestCase
{
    /**
     * One entry per specialized option class: its `fromArray()` as a first-class
     * callable, plus a payload that constructor accepts unchanged.
     *
     * The factory is passed rather than the class name because `fromArray()` is
     * declared on each concrete class, not on {@see AbstractOptions} — a
     * `class-string<AbstractOptions>` would promise a method the base does not
     * have, and PHPStan says so at level 10.
     *
     * @return array<string, array{0: callable(array<string, mixed>): AbstractOptions, 1: array<string, mixed>}>
     */
    public static function optionFactories(): array
    {
        return [
            'image generation' => [ImageGenerationOptions::fromArray(...), ['model' => 'dall-e-3', 'size' => '1024x1024']],
            'speech synthesis' => [SpeechSynthesisOptions::fromArray(...), ['model' => 'tts-1', 'voice' => 'alloy']],
            'transcription'    => [TranscriptionOptions::fromArray(...), ['model' => 'whisper-1', 'language' => 'en']],
            'deepl'            => [DeepLOptions::fromArray(...), ['formality' => 'more']],
        ];
    }

    /**
     * @param callable(array<string, mixed>): AbstractOptions $fromArray
     * @param array<string, mixed>                            $payload
     */
    #[Test]
    #[DataProvider('optionFactories')]
    public function fromArrayCarriesTheCallerIdentity(callable $fromArray, array $payload): void
    {
        $options = $fromArray($payload + [
            'callerSourceExtension' => 'nr_landingpage',
            'callerSourceOperation' => 'generateHeroImage',
        ]);

        self::assertSame('nr_landingpage', $options->getCallerSourceExtension());
        self::assertSame('generateHeroImage', $options->getCallerSourceOperation());
    }

    /**
     * An operation is optional; the extension alone is enough to attribute a
     * call, and the operation then reads as unspecified rather than absent.
     *
     * @param callable(array<string, mixed>): AbstractOptions $fromArray
     * @param array<string, mixed>                            $payload
     */
    #[Test]
    #[DataProvider('optionFactories')]
    public function anExtensionWithoutAnOperationYieldsAnEmptyOperation(callable $fromArray, array $payload): void
    {
        $options = $fromArray($payload + ['callerSourceExtension' => 'nr_repurpose']);

        self::assertSame('nr_repurpose', $options->getCallerSourceExtension());
        self::assertSame('', $options->getCallerSourceOperation());
    }

    /**
     * An unannotated call must stay indistinguishable from a pre-feature one:
     * null, not the empty string, so the telemetry row keeps its own default.
     *
     * @param callable(array<string, mixed>): AbstractOptions $fromArray
     * @param array<string, mixed>                            $payload
     */
    #[Test]
    #[DataProvider('optionFactories')]
    public function anUnannotatedArrayLeavesTheIdentityUnset(callable $fromArray, array $payload): void
    {
        $options = $fromArray($payload);

        self::assertNull($options->getCallerSourceExtension());
        self::assertNull($options->getCallerSourceOperation());
    }

    /**
     * An empty extension key is not an identity. Accepting it would put a `''`
     * source on the row, which reads as "attributed to nothing" rather than
     * "not attributed".
     *
     * @param callable(array<string, mixed>): AbstractOptions $fromArray
     * @param array<string, mixed>                            $payload
     */
    #[Test]
    #[DataProvider('optionFactories')]
    public function anEmptyExtensionKeyIsIgnored(callable $fromArray, array $payload): void
    {
        $options = $fromArray($payload + ['callerSourceExtension' => '', 'callerSourceOperation' => 'x']);

        self::assertNull($options->getCallerSourceExtension());
    }

    /**
     * The guard that matters most: the identity must never reach the provider.
     *
     * `toArray()` builds the upstream request payload. If a later change made it
     * "symmetric" with `fromArray()`, the calling extension's key would be sent
     * to OpenAI, FAL or DeepL as a request parameter — a silent data leak in a
     * change that would look like a tidy-up.
     *
     * @param callable(array<string, mixed>): AbstractOptions $fromArray
     * @param array<string, mixed>                            $payload
     */
    #[Test]
    #[DataProvider('optionFactories')]
    public function toArrayNeverExposesTheCallerIdentity(callable $fromArray, array $payload): void
    {
        $options = $fromArray($payload + [
            'callerSourceExtension' => 'nr_landingpage',
            'callerSourceOperation' => 'generateHeroImage',
        ]);

        $encoded = json_encode($options->toArray());
        self::assertIsString($encoded);

        self::assertArrayNotHasKey('callerSourceExtension', $options->toArray());
        self::assertArrayNotHasKey('callerSourceOperation', $options->toArray());
        self::assertStringNotContainsString('nr_landingpage', $encoded);
        self::assertStringNotContainsString('generateHeroImage', $encoded);
    }
}
