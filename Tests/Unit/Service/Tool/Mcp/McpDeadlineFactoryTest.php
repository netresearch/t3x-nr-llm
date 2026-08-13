<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(McpDeadlineFactory::class)]
final class McpDeadlineFactoryTest extends AbstractUnitTestCase
{
    /**
     * The number is a product decision and is stated, not inherited: 15 seconds
     * was one request's timeout before this existed and the 5 on top pay for
     * the handshake in front of the work. It buys the payload leg no floor —
     * {@see McpClientTest::theLastLegOfAToolCallGetsOnlyWhatTheHandshakeLeft}
     * is where what that leg actually gets is asserted.
     */
    #[Test]
    public function anUnconfiguredInstallationGetsTheStatedDefault(): void
    {
        self::assertSame(20, McpDeadlineFactory::DEFAULT_TOTAL_SECONDS);
        self::assertSame(20, $this->factoryFor(null)->forOperation()->totalSeconds());
    }

    /**
     * An operator with a legitimately slow server raises it, which is the whole
     * reason it is a configuration field rather than a constant.
     */
    #[Test]
    public function aConfiguredBudgetIsWhatTheOperationGets(): void
    {
        self::assertSame(90, $this->factoryFor('90')->forOperation()->totalSeconds());
        self::assertSame(45, $this->factoryFor(45)->forOperation()->totalSeconds());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableValues(): array
    {
        return [
            'never written'      => [''],
            'whitespace'         => ['   '],
            'not a number'       => ['soon'],
            'switched off'       => ['0'],
            'negative'           => ['-5'],
            'not even a scalar'  => [['20']],
        ];
    }

    /**
     * The budget cannot be switched off. A zero or negative total would exhaust
     * before the first leg, which is an MCP client that never calls anything.
     */
    #[Test]
    #[DataProvider('unusableValues')]
    public function anUnusableValueFallsBackToTheDefault(mixed $configured): void
    {
        self::assertSame(20, $this->factoryFor($configured)->forOperation()->totalSeconds());
    }

    /**
     * An installation whose extension configuration cannot be read still has to
     * be able to call an MCP server.
     */
    #[Test]
    public function unreadableConfigurationFallsBackToTheDefault(): void
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new ExtensionConfigurationExtensionNotConfiguredException('not configured', 1799990243),
        );

        $factory = new McpDeadlineFactory(new FakeMcpClock(), $extensionConfiguration);

        self::assertSame(20, $factory->forOperation()->totalSeconds());
    }

    /**
     * Each operation gets its OWN budget. A factory that handed the same object
     * out twice would let one slow tool call exhaust the next one.
     */
    #[Test]
    public function eachOperationOpensItsOwnBudget(): void
    {
        $clock   = new FakeMcpClock();
        $factory = new McpDeadlineFactory($clock, $this->configurationReturning(null));

        $first = $factory->forOperation();
        $clock->advanceSeconds(18.0);
        $second = $factory->forOperation();

        self::assertSame(2, $first->legTimeoutSeconds());
        self::assertSame(20, $second->legTimeoutSeconds());
    }

    #[Test]
    public function theBudgetIsMeasuredOnTheInjectedClock(): void
    {
        $clock    = new FakeMcpClock();
        $deadline = (new McpDeadlineFactory($clock, $this->configurationReturning(null)))->forOperation();

        $clock->advanceSeconds(20.0);

        self::assertTrue($deadline->isExhausted());
        self::assertInstanceOf(McpOperationDeadline::class, $deadline);
    }

    private function factoryFor(mixed $configured): McpDeadlineFactory
    {
        return new McpDeadlineFactory(new FakeMcpClock(), $this->configurationReturning($configured));
    }

    private function configurationReturning(mixed $configured): ExtensionConfiguration
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnMap([
            ['nr_llm', 'mcpOperationTimeout', $configured],
        ]);

        return $extensionConfiguration;
    }
}
