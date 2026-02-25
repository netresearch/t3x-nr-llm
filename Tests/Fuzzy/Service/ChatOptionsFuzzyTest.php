<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Service;

use Eris\Generator;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Property-based tests for ChatOptions.
 */
#[CoversClass(ChatOptions::class)]
class ChatOptionsFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function temperatureIsClampedToValidRange(): void
    {
        $this
            ->forAll($this->floatBetween(0.0, 2.0))
            ->then(function (float $temperature): void {
                $options = new ChatOptions(temperature: $temperature);
                $array = $options->toArray();

                $this->assertArrayHasKey('temperature', $array);
                $this->assertGreaterThanOrEqual(0.0, $array['temperature']);
                $this->assertLessThanOrEqual(2.0, $array['temperature']);
            });
    }

    #[Test]
    public function maxTokensIsAlwaysPositive(): void
    {
        $this
            ->forAll(Generator\pos()) // @phpstan-ignore function.notFound
            ->then(function (int $maxTokens): void {
                $options = new ChatOptions(maxTokens: $maxTokens);
                $array = $options->toArray();

                $this->assertArrayHasKey('max_tokens', $array);
                $this->assertGreaterThan(0, $array['max_tokens']);
            });
    }

    #[Test]
    public function topPIsClampedToValidRange(): void
    {
        $this
            ->forAll($this->floatBetween(0.0, 1.0))
            ->then(function (float $topP): void {
                $options = new ChatOptions(topP: $topP);
                $array = $options->toArray();

                $this->assertArrayHasKey('top_p', $array);
                $this->assertGreaterThanOrEqual(0.0, $array['top_p']);
                $this->assertLessThanOrEqual(1.0, $array['top_p']);
            });
    }

    #[Test]
    public function presencePenaltyIsClampedToValidRange(): void
    {
        $this
            ->forAll($this->floatBetween(-2.0, 2.0))
            ->then(function (float $penalty): void {
                $options = new ChatOptions(presencePenalty: $penalty);
                $array = $options->toArray();

                $this->assertArrayHasKey('presence_penalty', $array);
                $this->assertGreaterThanOrEqual(-2.0, $array['presence_penalty']);
                $this->assertLessThanOrEqual(2.0, $array['presence_penalty']);
            });
    }

    #[Test]
    public function frequencyPenaltyIsClampedToValidRange(): void
    {
        $this
            ->forAll($this->floatBetween(-2.0, 2.0))
            ->then(function (float $penalty): void {
                $options = new ChatOptions(frequencyPenalty: $penalty);
                $array = $options->toArray();

                $this->assertArrayHasKey('frequency_penalty', $array);
                $this->assertGreaterThanOrEqual(-2.0, $array['frequency_penalty']);
                $this->assertLessThanOrEqual(2.0, $array['frequency_penalty']);
            });
    }

    #[Test]
    public function factoryPresetsReturnValidOptions(): void
    {
        $this
            ->forAll(Generator\elements(['factual', 'creative', 'balanced', 'json', 'code'])) // @phpstan-ignore function.notFound
            ->then(function (string $preset): void {
                $options = match ($preset) {
                    'factual' => ChatOptions::factual(),
                    'creative' => ChatOptions::creative(),
                    'balanced' => ChatOptions::balanced(),
                    'json' => ChatOptions::json(),
                    default => ChatOptions::code(),
                };

                $array = $options->toArray();

                // All presets should have valid temperature
                $this->assertArrayHasKey('temperature', $array);
                $this->assertGreaterThanOrEqual(0.0, $array['temperature']);
                $this->assertLessThanOrEqual(2.0, $array['temperature']);
            });
    }

    #[Test]
    public function mergePreservesExplicitValuesOverDefaults(): void
    {
        $this
            ->forAll(
                $this->floatBetween(0.0, 2.0),
                Generator\pos(), // @phpstan-ignore function.notFound
            )
            ->then(function (float $temperature, int $maxTokens): void {
                $options = new ChatOptions(
                    temperature: $temperature,
                    maxTokens: $maxTokens,
                );

                $overrides = ['model' => 'gpt-4'];
                $merged = $options->merge($overrides);

                $this->assertEqualsWithDelta($temperature, $merged['temperature'], 0.0001);
                $this->assertEquals($maxTokens, $merged['max_tokens']);
                $this->assertEquals('gpt-4', $merged['model']);
            });
    }

    #[Test]
    public function toArrayProducesConsistentOutput(): void
    {
        $this
            ->forAll(
                $this->floatBetween(0.0, 2.0),
                Generator\choose(1, 10000), // @phpstan-ignore function.notFound
            )
            ->then(function (float $temp, int $maxTokens): void {
                $options = new ChatOptions(
                    temperature: $temp,
                    maxTokens: $maxTokens,
                );

                $array1 = $options->toArray();
                $array2 = $options->toArray();

                $this->assertEquals($array1, $array2);
            });
    }

    #[Test]
    public function fluentSettersAreImmutable(): void
    {
        $this
            ->forAll(
                $this->floatBetween(0.0, 2.0),
                $this->floatBetween(0.0, 2.0),
            )
            ->then(function (float $temp1, float $temp2): void {
                $options1 = new ChatOptions(temperature: $temp1);
                $options2 = $options1->withTemperature($temp2);

                // Original should be unchanged
                $this->assertEqualsWithDelta($temp1, $options1->getTemperature(), 0.0001);
                // New should have new value
                $this->assertEqualsWithDelta($temp2, $options2->getTemperature(), 0.0001);
                // They should be different objects
                $this->assertNotSame($options1, $options2);
            });
    }
}
