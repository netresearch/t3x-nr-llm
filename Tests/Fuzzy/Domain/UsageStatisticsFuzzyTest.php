<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Domain;

use Eris\Generator;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Property-based tests for UsageStatistics.
 */
#[CoversNothing] // Domain/Model excluded from coverage in Build/UnitTests.xml
class UsageStatisticsFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function totalTokensCanBeAnyNonNegativeValue(): void
    {
        $this
            ->forAll(
                Generator\choose(0, 100000), // @phpstan-ignore function.notFound
                Generator\choose(0, 100000), // @phpstan-ignore function.notFound
            )
            ->then(function (int $promptTokens, int $completionTokens): void {
                $totalTokens = $promptTokens + $completionTokens;
                $usage = new UsageStatistics(
                    promptTokens: $promptTokens,
                    completionTokens: $completionTokens,
                    totalTokens: $totalTokens,
                );

                $this->assertEquals($promptTokens, $usage->promptTokens);
                $this->assertEquals($completionTokens, $usage->completionTokens);
                $this->assertEquals($totalTokens, $usage->totalTokens);
            });
    }

    #[Test]
    public function usageStatisticsPreservesValues(): void
    {
        $this
            ->forAll(
                Generator\pos(), // @phpstan-ignore function.notFound
                Generator\pos(), // @phpstan-ignore function.notFound
                Generator\pos(), // @phpstan-ignore function.notFound
            )
            ->then(function (int $prompt, int $completion, int $total): void {
                $usage = new UsageStatistics(
                    promptTokens: $prompt,
                    completionTokens: $completion,
                    totalTokens: $total,
                );

                $this->assertSame($prompt, $usage->promptTokens);
                $this->assertSame($completion, $usage->completionTokens);
                $this->assertSame($total, $usage->totalTokens);
            });
    }

    #[Test]
    public function usageStatisticsAreImmutable(): void
    {
        $this
            ->forAll(
                Generator\pos(), // @phpstan-ignore function.notFound
                Generator\pos(), // @phpstan-ignore function.notFound
                Generator\pos(), // @phpstan-ignore function.notFound
            )
            ->then(function (int $prompt, int $completion, int $total): void {
                $usage = new UsageStatistics(
                    promptTokens: $prompt,
                    completionTokens: $completion,
                    totalTokens: $total,
                );

                // Create a second instance with same values
                $usage2 = new UsageStatistics(
                    promptTokens: $prompt,
                    completionTokens: $completion,
                    totalTokens: $total,
                );

                // Both should have identical values
                $this->assertEquals($usage->promptTokens, $usage2->promptTokens);
                $this->assertEquals($usage->completionTokens, $usage2->completionTokens);
                $this->assertEquals($usage->totalTokens, $usage2->totalTokens);
            });
    }
}
