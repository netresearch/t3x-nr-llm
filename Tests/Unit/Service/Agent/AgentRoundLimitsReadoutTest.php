<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Service\Agent\AgentRoundLimits;
use Netresearch\NrLlm\Service\Agent\AgentRoundLimitsReadout;
use Netresearch\NrLlm\Service\Governance\EffectivePolicyRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(AgentRoundLimitsReadout::class)]
final class AgentRoundLimitsReadoutTest extends TestCase
{
    #[Test]
    public function theRowsShowTheConfiguredValuesWithTheirReader(): void
    {
        $rows = $this->rows(['defaultMaxIterations' => '8', 'maxIterations' => '30']);

        self::assertSame(['agent.defaultMaxIterations', 'agent.maxIterations'], array_map(static fn (EffectivePolicyRow $row): string => $row->key, $rows));
        self::assertSame(['8', '30'], array_map(static fn (EffectivePolicyRow $row): ?string => $row->value, $rows));
        self::assertSame([AgentRoundLimits::class, AgentRoundLimits::class], array_map(static fn (EffectivePolicyRow $row): string => $row->reader, $rows));
        self::assertNull($rows[0]->noteKey);
        self::assertNull($rows[1]->noteKey);
    }

    #[Test]
    public function aDefaultAboveTheCeilingShowsTheCeilingAndSaysWhy(): void
    {
        $rows = $this->rows(['defaultMaxIterations' => '12', 'maxIterations' => '10']);

        self::assertSame('10', $rows[0]->value);
        self::assertSame(AgentRoundLimitsReadout::NOTE_DEFAULT_CLAMPED, $rows[0]->noteKey);
        self::assertSame('10', $rows[1]->value);
        self::assertNull($rows[1]->noteKey);
    }

    #[Test]
    public function invalidSettingsShowTheFallbacks(): void
    {
        $rows = $this->rows(['defaultMaxIterations' => '0', 'maxIterations' => 'many']);

        self::assertSame(['5', '20'], array_map(static fn (EffectivePolicyRow $row): ?string => $row->value, $rows));
    }

    /**
     * @param array<string, mixed> $agent
     *
     * @return list<EffectivePolicyRow>
     */
    private function rows(array $agent): array
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['agent' => $agent]);

        return (new AgentRoundLimitsReadout(new AgentRoundLimits($configuration)))->rows();
    }
}
