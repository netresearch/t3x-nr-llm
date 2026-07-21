<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Usage;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\Usage\UsageMetricsExtractorInterface;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Usage\DallEUsageExtractor;
use Netresearch\NrLlm\Specialized\Usage\DeepLUsageExtractor;
use Netresearch\NrLlm\Specialized\Usage\FalUsageExtractor;
use Netresearch\NrLlm\Specialized\Usage\TextToSpeechUsageExtractor;
use Netresearch\NrLlm\Specialized\Usage\WhisperUsageExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The extractors match on operation AND provider, so the two image extractors
 * (DALL·E and FAL share ImageGeneration) never both claim the same call, and a
 * speech extractor never claims a translation (ADR-100).
 */
#[CoversClass(DallEUsageExtractor::class)]
#[CoversClass(FalUsageExtractor::class)]
#[CoversClass(TextToSpeechUsageExtractor::class)]
#[CoversClass(WhisperUsageExtractor::class)]
#[CoversClass(DeepLUsageExtractor::class)]
final class UsageExtractorSupportTest extends TestCase
{
    /**
     * @return iterable<string, array{UsageMetricsExtractorInterface, ProviderOperation, string, bool}>
     */
    public static function supportMatrix(): iterable
    {
        $cost  = self::createStub(SpecializedCostCalculatorInterface::class);
        $dalle = new DallEUsageExtractor($cost);
        $fal   = new FalUsageExtractor();
        $tts   = new TextToSpeechUsageExtractor($cost);
        $whisper = new WhisperUsageExtractor($cost);
        $deepl = new DeepLUsageExtractor();

        yield 'dall-e claims its own image call' => [$dalle, ProviderOperation::ImageGeneration, 'dall-e', true];
        yield 'dall-e ignores a FAL image call' => [$dalle, ProviderOperation::ImageGeneration, 'fal', false];
        yield 'fal claims its own image call' => [$fal, ProviderOperation::ImageGeneration, 'fal', true];
        yield 'fal ignores a DALL-E image call' => [$fal, ProviderOperation::ImageGeneration, 'dall-e', false];
        yield 'tts claims speech synthesis' => [$tts, ProviderOperation::SpeechSynthesis, 'tts', true];
        yield 'tts ignores transcription' => [$tts, ProviderOperation::Transcription, 'tts', false];
        yield 'whisper claims transcription' => [$whisper, ProviderOperation::Transcription, 'whisper', true];
        yield 'whisper ignores speech synthesis' => [$whisper, ProviderOperation::SpeechSynthesis, 'whisper', false];
        yield 'deepl claims translation' => [$deepl, ProviderOperation::Translation, 'deepl', true];
        yield 'deepl ignores its metadata sub-call' => [$deepl, ProviderOperation::Metadata, 'deepl', false];
    }

    #[Test]
    #[DataProvider('supportMatrix')]
    public function supportsMatchesOnOperationAndProvider(
        UsageMetricsExtractorInterface $extractor,
        ProviderOperation $operation,
        string $provider,
        bool $expected,
    ): void {
        $context = ProviderCallContext::forService($operation, $provider, 'model');

        self::assertSame($expected, $extractor->supports($context));
    }
}
