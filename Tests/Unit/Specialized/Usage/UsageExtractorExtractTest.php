<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Usage;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\Usage\ProviderUsageRecord;
use Netresearch\NrLlm\Provider\Middleware\Usage\SpecializedUsageIntent;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Usage\DallEUsageExtractor;
use Netresearch\NrLlm\Specialized\Usage\DeepLUsageExtractor;
use Netresearch\NrLlm\Specialized\Usage\FalUsageExtractor;
use Netresearch\NrLlm\Specialized\Usage\TextToSpeechUsageExtractor;
use Netresearch\NrLlm\Specialized\Usage\WhisperUsageExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What each extractor writes into the usage record — the numbers that become
 * billed cost rows (ADR-100). The support() routing is pinned next door in
 * {@see UsageExtractorSupportTest}; this file pins the money path, which until
 * now no test exercised at all.
 *
 * Two properties recur and are asserted per extractor rather than assumed:
 * a call without an attached intent records NOTHING (that is how DeepL's
 * language-detection sub-call stays out of the books), and a malformed
 * provider response degrades to zero units instead of throwing — a billing
 * row must never be the thing that breaks a working call.
 */
#[CoversClass(DallEUsageExtractor::class)]
#[CoversClass(FalUsageExtractor::class)]
#[CoversClass(TextToSpeechUsageExtractor::class)]
#[CoversClass(WhisperUsageExtractor::class)]
#[CoversClass(DeepLUsageExtractor::class)]
final class UsageExtractorExtractTest extends TestCase
{
    /**
     * @param array<string, mixed> $metadata
     */
    private function context(ProviderOperation $operation, string $provider, array $metadata = []): ProviderCallContext
    {
        return new ProviderCallContext(
            operation: $operation,
            correlationId: 'test-correlation',
            provider: $provider,
            model: 'test-model',
            metadata: $metadata,
        );
    }

    private function withIntent(ProviderOperation $operation, string $provider, SpecializedUsageIntent $intent): ProviderCallContext
    {
        return $this->context($operation, $provider, [SpecializedUsageIntent::METADATA_KEY => $intent]);
    }

    // ---- the shared guard: no intent, no record ----

    #[Test]
    public function everyExtractorRecordsNothingWithoutAnIntent(): void
    {
        $cost = self::createStub(SpecializedCostCalculatorInterface::class);

        $cases = [
            [new DallEUsageExtractor($cost), ProviderOperation::ImageGeneration, 'dall-e'],
            [new FalUsageExtractor(), ProviderOperation::ImageGeneration, 'fal'],
            [new TextToSpeechUsageExtractor($cost), ProviderOperation::SpeechSynthesis, 'tts'],
            [new WhisperUsageExtractor($cost), ProviderOperation::Transcription, 'whisper'],
            [new DeepLUsageExtractor(), ProviderOperation::Translation, 'deepl'],
        ];

        foreach ($cases as [$extractor, $operation, $provider]) {
            self::assertNull(
                $extractor->extract($this->context($operation, $provider), ['data' => []]),
                $provider . ' must not record a call that carries no intent',
            );
        }
    }

    // ---- DALL·E ----

    #[Test]
    public function dallEChargesThePerImageCatalogPriceWhenNoTokenUsageIsSent(): void
    {
        $cost = self::createMock(SpecializedCostCalculatorInterface::class);
        $cost->expects(self::once())
            ->method('estimateImageCost')
            ->with('dall-e-3', 'hd', '1024x1024', 2, 0, 0, 0)
            ->willReturn(0.16);

        $intent = new SpecializedUsageIntent(
            modelId: 'dall-e-3',
            modelUid: 4,
            configurationUid: 9,
            beUserUid: 3,
            size: '1024x1024',
            quality: 'hd',
        );

        $record = (new DallEUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::ImageGeneration, 'dall-e', $intent),
            ['data' => [['url' => 'a'], ['url' => 'b']]],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame('image', $record->serviceType);
        self::assertSame('dall-e', $record->provider);
        self::assertSame(['images' => 2, 'cost' => 0.16], $record->metrics);
        self::assertSame(9, $record->configurationUid);
        self::assertSame(4, $record->modelUid);
        self::assertSame('dall-e-3', $record->modelId);
        self::assertSame(3, $record->beUserUid);
    }

    #[Test]
    public function dallERecordsGptImageTokensAndPassesThemToThePrice(): void
    {
        $cost = self::createMock(SpecializedCostCalculatorInterface::class);
        $cost->expects(self::once())
            ->method('estimateImageCost')
            ->with('gpt-image-1', 'standard', '', 1, 120, 800, 90)
            ->willReturn(0.05);

        $intent = new SpecializedUsageIntent(modelId: 'gpt-image-1');

        $record = (new DallEUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::ImageGeneration, 'dall-e', $intent),
            [
                'data'  => [['b64_json' => 'x']],
                'usage' => [
                    'input_tokens'         => 120,
                    'output_tokens'        => 800,
                    'total_tokens'         => 920,
                    'input_tokens_details' => ['image_tokens' => 90],
                ],
            ],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(920, $record->metrics['tokens'] ?? null);
        self::assertSame(120, $record->metrics['promptTokens'] ?? null);
        self::assertSame(800, $record->metrics['completionTokens'] ?? null);
    }

    /**
     * A missing total must become input+output, not zero: the analytics sum
     * over `tokens`, and a zero would under-report a call that did bill.
     */
    #[Test]
    public function dallEDerivesAMissingTotalFromInputPlusOutput(): void
    {
        $cost = self::createStub(SpecializedCostCalculatorInterface::class);

        $record = (new DallEUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::ImageGeneration, 'dall-e', new SpecializedUsageIntent(modelId: 'gpt-image-1')),
            ['data' => [[]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 30]],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(40, $record->metrics['tokens'] ?? null);
    }

    #[Test]
    public function dallECountsZeroImagesOnAMalformedResponse(): void
    {
        $cost = self::createStub(SpecializedCostCalculatorInterface::class);

        $record = (new DallEUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::ImageGeneration, 'dall-e', new SpecializedUsageIntent(modelId: 'dall-e-3')),
            'not an array at all',
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(0, $record->metrics['images'] ?? null);
    }

    // ---- Whisper ----

    #[Test]
    public function whisperBillsTheReportedAudioDuration(): void
    {
        $cost = self::createMock(SpecializedCostCalculatorInterface::class);
        $cost->expects(self::once())
            ->method('estimateTranscriptionCost')
            ->with('whisper-1', 12.34)
            ->willReturn(0.074);

        $record = (new WhisperUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::Transcription, 'whisper', new SpecializedUsageIntent(modelId: 'whisper-1', beUserUid: 5)),
            ['duration' => 12.34, 'text' => 'hello'],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame('speech', $record->serviceType);
        self::assertSame(12, $record->metrics['audioSeconds'] ?? null);
        self::assertSame(0.074, $record->metrics['cost'] ?? null);
        self::assertSame(5, $record->beUserUid);
    }

    /**
     * A sub-half-second clip must not round to 0 seconds while carrying a
     * positive cost — units and cost stay consistent.
     */
    #[Test]
    public function whisperNeverBillsZeroSecondsForAPositiveDuration(): void
    {
        $cost = self::createStub(SpecializedCostCalculatorInterface::class);
        $cost->method('estimateTranscriptionCost')->willReturn(0.001);

        $record = (new WhisperUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::Transcription, 'whisper', new SpecializedUsageIntent(modelId: 'whisper-1')),
            ['duration' => 0.4],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(1, $record->metrics['audioSeconds'] ?? null);
    }

    /**
     * Only `verbose_json` reports a duration. A plain-text response still
     * records the request — with no units and no cost, never a guessed one.
     */
    #[Test]
    public function whisperRecordsTheRequestWithoutCostWhenNoDurationIsReported(): void
    {
        $cost = self::createMock(SpecializedCostCalculatorInterface::class);
        $cost->expects(self::never())->method('estimateTranscriptionCost');

        $record = (new WhisperUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::Transcription, 'whisper', new SpecializedUsageIntent(modelId: 'whisper-1')),
            'a plain transcription string',
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame([], $record->metrics);
    }

    // ---- text-to-speech ----

    #[Test]
    public function ttsBillsTheInputCharactersFromTheIntentNotTheResponse(): void
    {
        $cost = self::createMock(SpecializedCostCalculatorInterface::class);
        $cost->expects(self::once())
            ->method('estimateSpeechSynthesisCost')
            ->with('tts-1', 240)
            ->willReturn(0.0036);

        $record = (new TextToSpeechUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::SpeechSynthesis, 'tts', new SpecializedUsageIntent(modelId: 'tts-1', characters: 240)),
            "\x00\x01raw audio bytes",
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(['characters' => 240, 'cost' => 0.0036], $record->metrics);
    }

    #[Test]
    public function ttsFallsBackToZeroCharactersWhenTheIntentCarriesNone(): void
    {
        $cost = self::createMock(SpecializedCostCalculatorInterface::class);
        $cost->expects(self::once())
            ->method('estimateSpeechSynthesisCost')
            ->with('tts-1', 0)
            ->willReturn(0.0);

        $record = (new TextToSpeechUsageExtractor($cost))->extract(
            $this->withIntent(ProviderOperation::SpeechSynthesis, 'tts', new SpecializedUsageIntent(modelId: 'tts-1')),
            '',
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(0, $record->metrics['characters'] ?? null);
    }

    // ---- DeepL ----

    #[Test]
    public function deepLRecordsCharactersAndBatchSizeWithoutComputingACost(): void
    {
        $record = (new DeepLUsageExtractor())->extract(
            $this->withIntent(ProviderOperation::Translation, 'deepl', new SpecializedUsageIntent(modelId: 'deepl', beUserUid: 7, characters: 1200, batchSize: 3)),
            ['translations' => []],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame('translation', $record->serviceType);
        self::assertSame(['characters' => 1200, 'batch_size' => 3], $record->metrics);
        self::assertArrayNotHasKey('cost', $record->metrics);
        self::assertSame(7, $record->beUserUid);
    }

    #[Test]
    public function deepLOmitsTheBatchSizeForASingleTranslation(): void
    {
        $record = (new DeepLUsageExtractor())->extract(
            $this->withIntent(ProviderOperation::Translation, 'deepl', new SpecializedUsageIntent(modelId: 'deepl', characters: 80)),
            ['translations' => []],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(['characters' => 80], $record->metrics);
    }

    // ---- FAL ----

    /**
     * FAL publishes no static price list, so the extractor must record the
     * image count and deliberately NO cost — a fabricated price would be worse
     * than an absent one.
     */
    #[Test]
    public function falCountsTheReturnedImagesAndRecordsNoCost(): void
    {
        $record = (new FalUsageExtractor())->extract(
            $this->withIntent(ProviderOperation::ImageGeneration, 'fal', new SpecializedUsageIntent(modelId: 'flux/dev', modelUid: 11)),
            ['images' => [['url' => 'a'], ['url' => 'b'], ['url' => 'c']]],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(['images' => 3], $record->metrics);
        self::assertArrayNotHasKey('cost', $record->metrics);
        self::assertSame(11, $record->modelUid);
    }

    #[Test]
    public function falCountsZeroImagesOnAMalformedResponse(): void
    {
        $record = (new FalUsageExtractor())->extract(
            $this->withIntent(ProviderOperation::ImageGeneration, 'fal', new SpecializedUsageIntent(modelId: 'flux/dev')),
            ['images' => 'not a list'],
        );

        self::assertInstanceOf(ProviderUsageRecord::class, $record);
        self::assertSame(['images' => 0], $record->metrics);
    }
}
