<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Domain;

use Eris\Generator;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Property-based tests for LlmConfiguration.
 *
 * Tests parameter validation and clamping with random inputs.
 */
#[CoversNothing]
class LlmConfigurationFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function temperatureIsClampedToValidRange(): void
    {
        $this
            ->forAll(Generator\float())
            ->then(function (float $temp): void {
                $config = new LlmConfiguration();
                $config->setTemperature($temp);

                $result = $config->getTemperature();

                // Temperature should be clamped to 0.0 - 2.0
                $this->assertGreaterThanOrEqual(0.0, $result);
                $this->assertLessThanOrEqual(2.0, $result);
            });
    }

    #[Test]
    public function topPIsClampedToValidRange(): void
    {
        $this
            ->forAll(Generator\float())
            ->then(function (float $topP): void {
                $config = new LlmConfiguration();
                $config->setTopP($topP);

                $result = $config->getTopP();

                // Top P should be clamped to 0.0 - 1.0
                $this->assertGreaterThanOrEqual(0.0, $result);
                $this->assertLessThanOrEqual(1.0, $result);
            });
    }

    #[Test]
    public function frequencyPenaltyIsClampedToValidRange(): void
    {
        $this
            ->forAll(Generator\float())
            ->then(function (float $penalty): void {
                $config = new LlmConfiguration();
                $config->setFrequencyPenalty($penalty);

                $result = $config->getFrequencyPenalty();

                // Frequency penalty should be clamped to -2.0 to 2.0
                $this->assertGreaterThanOrEqual(-2.0, $result);
                $this->assertLessThanOrEqual(2.0, $result);
            });
    }

    #[Test]
    public function presencePenaltyIsClampedToValidRange(): void
    {
        $this
            ->forAll(Generator\float())
            ->then(function (float $penalty): void {
                $config = new LlmConfiguration();
                $config->setPresencePenalty($penalty);

                $result = $config->getPresencePenalty();

                // Presence penalty should be clamped to -2.0 to 2.0
                $this->assertGreaterThanOrEqual(-2.0, $result);
                $this->assertLessThanOrEqual(2.0, $result);
            });
    }

    #[Test]
    public function maxTokensIsAtLeastOne(): void
    {
        $this
            ->forAll(Generator\int())
            ->then(function (int $tokens): void {
                $config = new LlmConfiguration();
                $config->setMaxTokens($tokens);

                $result = $config->getMaxTokens();

                // Max tokens should be at least 1
                $this->assertGreaterThanOrEqual(1, $result);
            });
    }

    #[Test]
    public function identifierPreservesAsciiCharacters(): void
    {
        $this
            ->forAll(
                Generator\suchThat(
                    static fn(string $s) => preg_match('/^[a-zA-Z0-9_-]+$/', $s) === 1,
                    Generator\string(),
                ),
            )
            ->then(function (string $identifier): void {
                $config = new LlmConfiguration();
                $config->setIdentifier($identifier);

                $this->assertSame($identifier, $config->getIdentifier());
            });
    }

    #[Test]
    public function namePreservesUnicodeCharacters(): void
    {
        $this
            ->forAll(Generator\string())
            ->then(function (string $name): void {
                $config = new LlmConfiguration();
                $config->setName($name);

                $this->assertSame($name, $config->getName());
            });
    }

    #[Test]
    public function systemPromptPreservesContent(): void
    {
        $this
            ->forAll(Generator\string())
            ->then(function (string $prompt): void {
                $config = new LlmConfiguration();
                $config->setSystemPrompt($prompt);

                $this->assertSame($prompt, $config->getSystemPrompt());
            });
    }

    #[Test]
    public function isActiveReturnsBoolean(): void
    {
        $this
            ->forAll(Generator\bool())
            ->then(function (bool $active): void {
                $config = new LlmConfiguration();
                $config->setIsActive($active);

                $this->assertSame($active, $config->isActive());
            });
    }

    #[Test]
    public function isDefaultReturnsBoolean(): void
    {
        $this
            ->forAll(Generator\bool())
            ->then(function (bool $default): void {
                $config = new LlmConfiguration();
                $config->setIsDefault($default);

                $this->assertSame($default, $config->isDefault());
            });
    }

    #[Test]
    public function validTemperatureValuesArePreserved(): void
    {
        $this
            ->forAll($this->temperature())
            ->then(function (float $temp): void {
                $config = new LlmConfiguration();
                $config->setTemperature($temp);

                // Valid values should be preserved exactly (within float precision)
                $this->assertEqualsWithDelta($temp, $config->getTemperature(), 0.0001);
            });
    }

    #[Test]
    public function validTopPValuesArePreserved(): void
    {
        $this
            ->forAll($this->topP())
            ->then(function (float $topP): void {
                $config = new LlmConfiguration();
                $config->setTopP($topP);

                $this->assertEqualsWithDelta($topP, $config->getTopP(), 0.0001);
            });
    }

    #[Test]
    public function validPenaltyValuesArePreserved(): void
    {
        $this
            ->forAll($this->penalty())
            ->then(function (float $penalty): void {
                $config = new LlmConfiguration();
                $config->setFrequencyPenalty($penalty);
                $config->setPresencePenalty($penalty);

                $this->assertEqualsWithDelta($penalty, $config->getFrequencyPenalty(), 0.0001);
                $this->assertEqualsWithDelta($penalty, $config->getPresencePenalty(), 0.0001);
            });
    }

    #[Test]
    public function positiveMaxTokensArePreserved(): void
    {
        $this
            ->forAll($this->positiveInt())
            ->then(function (int $tokens): void {
                $config = new LlmConfiguration();
                $config->setMaxTokens($tokens);

                $this->assertSame($tokens, $config->getMaxTokens());
            });
    }

    #[Test]
    public function optionsJsonIsValidWhenSet(): void
    {
        $this
            ->forAll(
                Generator\associative([
                    'key1' => Generator\string(),
                    'key2' => Generator\int(),
                    'key3' => Generator\bool(),
                ]),
            )
            ->then(function (array $options): void {
                $config = new LlmConfiguration();
                $json = json_encode($options);
                $config->setOptions($json !== false ? $json : '{}');

                $decoded = $config->getOptionsArray();

                // Should be an array (may be empty if JSON was invalid)
                $this->assertIsArray($decoded);
            });
    }
}
