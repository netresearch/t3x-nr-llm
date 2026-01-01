<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Domain;

use Eris\Generator;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Property-based tests for Model entity.
 *
 * Tests input validation and edge cases with random inputs.
 */
#[CoversNothing]
class ModelFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function identifierPreservesValidCharacters(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\suchThat(
                    static fn(string $s) => preg_match('/^[a-zA-Z0-9_.-]+$/', $s) === 1 && strlen($s) <= 100,
                    Generator\string(), // @phpstan-ignore function.notFound
                ),
            )
            ->then(function (string $identifier): void {
                $model = new Model();
                $model->setIdentifier($identifier);

                $this->assertSame($identifier, $model->getIdentifier());
            });
    }

    #[Test]
    public function namePreservesAnyString(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\string())
            ->then(function (string $name): void {
                $model = new Model();
                $model->setName($name);

                $this->assertSame($name, $model->getName());
            });
    }

    #[Test]
    public function modelIdPreservesValue(): void
    {
        // Model IDs like "gpt-4o", "claude-3-sonnet", "gemini-pro"
        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\suchThat(
                    static fn(string $s) => preg_match('/^[a-zA-Z0-9_.-]+$/', $s) === 1,
                    Generator\string(), // @phpstan-ignore function.notFound
                ),
            )
            ->then(function (string $modelId): void {
                $model = new Model();
                $model->setModelId($modelId);

                $this->assertSame($modelId, $model->getModelId());
            });
    }

    #[Test]
    public function contextLengthIsNonNegative(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\int())
            ->then(function (int $length): void {
                $model = new Model();
                $model->setContextLength($length);

                $result = $model->getContextLength();

                // Context length should be non-negative
                $this->assertGreaterThanOrEqual(0, $result);
            });
    }

    #[Test]
    public function maxOutputTokensIsNonNegative(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\int())
            ->then(function (int $tokens): void {
                $model = new Model();
                $model->setMaxOutputTokens($tokens);

                $result = $model->getMaxOutputTokens();

                // Max output tokens should be non-negative
                $this->assertGreaterThanOrEqual(0, $result);
            });
    }

    #[Test]
    public function positiveContextLengthIsPreserved(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\choose(1, 200000))
            ->then(function (int $length): void {
                $model = new Model();
                $model->setContextLength($length);

                $this->assertSame($length, $model->getContextLength());
            });
    }

    #[Test]
    public function positiveMaxOutputTokensIsPreserved(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\choose(1, 16000))
            ->then(function (int $tokens): void {
                $model = new Model();
                $model->setMaxOutputTokens($tokens);

                $this->assertSame($tokens, $model->getMaxOutputTokens());
            });
    }

    #[Test]
    public function capabilitiesPreservesValidValues(): void
    {
        $validCapabilities = ['chat', 'completion', 'embeddings', 'vision', 'streaming', 'tools'];

        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\subset($validCapabilities),
            )
            ->then(function (array $capabilities): void {
                $model = new Model();
                $capsString = implode(',', $capabilities);
                $model->setCapabilities($capsString);

                $result = $model->getCapabilitiesArray();

                // Should contain only the capabilities we set (order may differ)
                foreach ($capabilities as $cap) {
                    $this->assertContains($cap, $result);
                }
            });
    }

    #[Test]
    public function costInputIsNonNegative(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\int())
            ->then(function (int $cost): void {
                $model = new Model();
                $model->setCostInput($cost);

                $result = $model->getCostInput();

                // Cost should be non-negative
                $this->assertGreaterThanOrEqual(0, $result);
            });
    }

    #[Test]
    public function costOutputIsNonNegative(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\int())
            ->then(function (int $cost): void {
                $model = new Model();
                $model->setCostOutput($cost);

                $result = $model->getCostOutput();

                // Cost should be non-negative
                $this->assertGreaterThanOrEqual(0, $result);
            });
    }

    #[Test]
    public function positiveCostsArePreserved(): void
    {
        $this
            ->forAll(
                Generator\choose(0, 10000), // @phpstan-ignore function.notFound
                Generator\choose(0, 30000), // @phpstan-ignore function.notFound
            )
            ->then(function (int $inputCost, int $outputCost): void {
                $model = new Model();
                $model->setCostInput($inputCost);
                $model->setCostOutput($outputCost);

                $this->assertSame($inputCost, $model->getCostInput());
                $this->assertSame($outputCost, $model->getCostOutput());
            });
    }

    #[Test]
    public function isActiveReturnsBoolean(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\bool())
            ->then(function (bool $active): void {
                $model = new Model();
                $model->setIsActive($active);

                $this->assertSame($active, $model->isActive());
            });
    }

    #[Test]
    public function isDefaultReturnsBoolean(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\bool())
            ->then(function (bool $default): void {
                $model = new Model();
                $model->setIsDefault($default);

                $this->assertSame($default, $model->isDefault());
            });
    }

    #[Test]
    public function descriptionPreservesContent(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\string())
            ->then(function (string $description): void {
                $model = new Model();
                $model->setDescription($description);

                $this->assertSame($description, $model->getDescription());
            });
    }
}
