<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The caller-source producer (ADR-177): the metadata keys TelemetryMiddleware
 * persists as source_extension / source_operation.
 */
#[CoversClass(CallMetadataFactory::class)]
final class CallMetadataFactoryCallerSourceTest extends TestCase
{
    #[Test]
    public function anAnnotatedCallBecomesTheTwoSourceKeys(): void
    {
        $options = (new ChatOptions())->withCallerSource('ai_seo_helper', 'requestAi');

        $metadata = (new CallMetadataFactory())->callerSource($options);

        self::assertSame([
            TelemetryMiddleware::METADATA_SOURCE_EXTENSION => 'ai_seo_helper',
            TelemetryMiddleware::METADATA_SOURCE_OPERATION => 'requestAi',
        ], $metadata);
    }

    #[Test]
    public function anUnannotatedCallProducesNoEntrySoTheRowKeepsItsEmptyDefaults(): void
    {
        self::assertSame([], (new CallMetadataFactory())->callerSource(new ChatOptions()));
    }

    #[Test]
    public function anEmptyExtensionProducesNoEntry(): void
    {
        // '' would claim an identity that names nothing; the row's '' default
        // already says "unannotated".
        $options = (new ChatOptions())->withCallerSource('');

        self::assertSame([], (new CallMetadataFactory())->callerSource($options));
    }

    #[Test]
    public function theOperationDefaultsToAnEmptyString(): void
    {
        $options = (new ChatOptions())->withCallerSource('ai_filemetadata');

        $metadata = (new CallMetadataFactory())->callerSource($options);

        self::assertSame('', $metadata[TelemetryMiddleware::METADATA_SOURCE_OPERATION]);
    }

    #[Test]
    public function everyOptionTypeCarriesTheChannel(): void
    {
        // The wither lives on AbstractOptions, so the vision path (the compat
        // layer's second consumer) annotates the same way as chat.
        $options = (new VisionOptions())->withCallerSource('ai_filemetadata', 'buildAltText');

        $metadata = (new CallMetadataFactory())->callerSource($options);

        self::assertSame('ai_filemetadata', $metadata[TelemetryMiddleware::METADATA_SOURCE_EXTENSION]);
    }

    #[Test]
    public function theKeySetIsDisjointFromTheOtherProducers(): void
    {
        $factory = new CallMetadataFactory();

        $merged = $factory->budget(5, 0.25)
            + $factory->idempotency('key-1')
            + $factory->callerSource((new ChatOptions())->withCallerSource('ai_seo_helper', 'requestAi'));

        // Disjointness is load-bearing: every call site merges with `+`, which
        // keeps the FIRST value on a collision.
        self::assertCount(5, $merged);
        self::assertSame('ai_seo_helper', $merged[TelemetryMiddleware::METADATA_SOURCE_EXTENSION]);
    }
}
