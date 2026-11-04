<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Generator;
use Netresearch\NrLlm\Service\Agent\AgentRoundLimits;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(AgentRoundLimits::class)]
final class AgentRoundLimitsTest extends TestCase
{
    #[Test]
    public function withoutAnyConfigurationTheFallbacksApply(): void
    {
        $limits = new AgentRoundLimits();

        self::assertSame(AgentRoundLimits::FALLBACK_DEFAULT, $limits->defaultRounds());
        self::assertSame(AgentRuntime::MAX_ITERATIONS, $limits->ceiling());
        self::assertSame(5, AgentRoundLimits::FALLBACK_DEFAULT);
        self::assertSame(20, $limits->ceiling());
    }

    #[Test]
    public function configuredValuesApply(): void
    {
        $limits = $this->limits(['defaultMaxIterations' => '8', 'maxIterations' => '30']);

        self::assertSame(8, $limits->defaultRounds());
        self::assertSame(30, $limits->ceiling());
    }

    #[Test]
    public function integerValuesFromAdditionalConfigurationApply(): void
    {
        $limits = $this->limits(['defaultMaxIterations' => 7, 'maxIterations' => 9]);

        self::assertSame(7, $limits->defaultRounds());
        self::assertSame(9, $limits->ceiling());
    }

    #[Test]
    public function surroundingWhitespaceIsIgnored(): void
    {
        self::assertSame(6, $this->limits(['defaultMaxIterations' => ' 6 '])->defaultRounds());
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function anInvalidDefaultFallsBackToFive(mixed $value): void
    {
        $limits = $this->limits(['defaultMaxIterations' => $value]);

        self::assertSame(5, $limits->defaultRounds());
        self::assertFalse($limits->defaultIsClamped());
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function anInvalidCeilingFallsBackToTwenty(mixed $value): void
    {
        self::assertSame(20, $this->limits(['maxIterations' => $value])->ceiling());
    }

    /**
     * @return Generator<string, array{mixed}>
     */
    public static function invalidValues(): Generator
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'zero' => ['0'];
        yield 'integer zero' => [0];
        yield 'negative' => ['-3'];
        yield 'negative integer' => [-3];
        yield 'not a number' => ['many'];
        yield 'a fraction' => ['7.5'];
        yield 'a float' => [7.5];
        yield 'a number with a unit' => ['10 rounds'];
        yield 'null' => [null];
        yield 'an array' => [['10']];
        yield 'a boolean' => [true];
    }

    #[Test]
    public function aDefaultAboveTheCeilingIsClampedToTheCeiling(): void
    {
        $limits = $this->limits(['defaultMaxIterations' => '12', 'maxIterations' => '10']);

        self::assertSame(10, $limits->defaultRounds());
        self::assertTrue($limits->defaultIsClamped());
    }

    #[Test]
    public function aDefaultEqualToTheCeilingIsNotClamped(): void
    {
        $limits = $this->limits(['defaultMaxIterations' => '10', 'maxIterations' => '10']);

        self::assertSame(10, $limits->defaultRounds());
        self::assertFalse($limits->defaultIsClamped());
    }

    #[Test]
    public function aCeilingBelowTheFallbackDefaultClampsTheDefault(): void
    {
        $limits = $this->limits(['maxIterations' => '3']);

        self::assertSame(3, $limits->defaultRounds());
        self::assertTrue($limits->defaultIsClamped());
    }

    #[Test]
    public function aRequestedCapIsClampedToTheCeiling(): void
    {
        $limits = $this->limits(['defaultMaxIterations' => '4', 'maxIterations' => '10']);

        self::assertSame(10, $limits->roundsFor(50));
        self::assertSame(10, $limits->roundsFor(10));
        self::assertSame(9, $limits->roundsFor(9));
        self::assertSame(1, $limits->roundsFor(1));
    }

    #[Test]
    public function noRequestedCapOrAnInvalidOneGetsTheDefault(): void
    {
        $limits = $this->limits(['defaultMaxIterations' => '4', 'maxIterations' => '10']);

        self::assertSame(4, $limits->roundsFor(null));
        self::assertSame(4, $limits->roundsFor(0));
        self::assertSame(4, $limits->roundsFor(-2));
    }

    #[Test]
    public function aConfigurationThatCannotBeReadFallsBack(): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('not configured'));
        $limits = new AgentRoundLimits($configuration);

        self::assertSame(5, $limits->defaultRounds());
        self::assertSame(20, $limits->ceiling());
    }

    #[Test]
    public function aMissingAgentSectionFallsBack(): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['agent' => 'not an array']);
        $limits = new AgentRoundLimits($configuration);

        self::assertSame(5, $limits->defaultRounds());
        self::assertSame(20, $limits->ceiling());
    }

    #[Test]
    public function theValuesAreReadOnEveryCallNotOnceAtConstruction(): void
    {
        $settings      = ['defaultMaxIterations' => '4', 'maxIterations' => '10'];
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(static function () use (&$settings): array {
            return ['agent' => $settings];
        });
        $limits = new AgentRoundLimits($configuration);

        self::assertSame(10, $limits->ceiling());
        $settings = ['defaultMaxIterations' => '6', 'maxIterations' => '7'];

        self::assertSame(7, $limits->ceiling());
        self::assertSame(6, $limits->defaultRounds());
    }

    #[Test]
    public function itReadsTheNrLlmConfiguration(): void
    {
        $configuration = $this->createMock(ExtensionConfiguration::class);
        $configuration->expects(self::atLeastOnce())->method('get')->with('nr_llm')->willReturn([]);

        (new AgentRoundLimits($configuration))->ceiling();
    }

    /**
     * @param array<string, mixed> $agent
     */
    private function limits(array $agent): AgentRoundLimits
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['agent' => $agent]);

        return new AgentRoundLimits($configuration);
    }
}
