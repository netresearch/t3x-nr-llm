<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A caller's site reaches the metadata key the term list reads (ADR-238,
 * ADR-244), from every option type, with or without a caller identity.
 */
#[CoversClass(CallMetadataFactory::class)]
final class CallMetadataFactorySiteTest extends TestCase
{
    #[Test]
    public function aSiteBecomesTheSiteKeyWithoutACallerIdentity(): void
    {
        self::assertSame(
            [ProviderCallContext::METADATA_SITE => 'shop'],
            (new CallMetadataFactory())->callerSource((new ChatOptions())->withSite('shop')),
        );
    }

    #[Test]
    public function aSiteJoinsTheCallerIdentity(): void
    {
        $metadata = (new CallMetadataFactory())->callerSource(
            (new VisionOptions())->withCallerSource('nr_repurpose', 'generateStory')->withSite('blog'),
        );

        self::assertSame('blog', $metadata[ProviderCallContext::METADATA_SITE]);
        self::assertCount(3, $metadata);
    }

    #[Test]
    public function aTranslationKeepsItsSiteForTheGlossaryAndStatesItForTheCheck(): void
    {
        $options = (new TranslationOptions())->withSite('shop');

        self::assertSame('shop', $options->getSite());
        self::assertSame([ProviderCallContext::METADATA_SITE => 'shop'], (new CallMetadataFactory())->callerSource($options));
    }

    #[Test]
    public function noSiteAndAnEmptySiteProduceNoKey(): void
    {
        $factory = new CallMetadataFactory();

        self::assertSame([], $factory->callerSource(new ChatOptions()));
        self::assertSame([], $factory->callerSource((new ChatOptions())->withSite('')));
    }
}
