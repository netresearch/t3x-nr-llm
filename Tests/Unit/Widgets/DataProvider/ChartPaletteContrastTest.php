<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\AgentRunsByStatusDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\GovernanceBlocksOverTimeDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\RunTerminationReasonsDataProvider;
use Netresearch\NrLlm\Widgets\DataProvider\ToolDenialsByReasonDataProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;

/**
 * Every dashboard chart colour stays visible in both backend schemes.
 *
 * Core's chart widget draws the dataset colours a data provider hands it in
 * the light and the dark scheme alike. A series colour is a graphic, so WCAG
 * 1.4.11 asks 3:1 against what it is drawn on: a white card in light, a
 * #262626 card in dark (the lighter of the dark surfaces). #E8C33D was 1.7:1
 * on white; #8E2A27 was 1.8:1 on the dark card.
 *
 * Neighbouring segments cannot also reach 3:1 against each other: a palette
 * held in the band that clears both cards leaves 1.00–1.43:1 between
 * neighbours. What separates doughnut segments is core's 2 px arc border,
 * #fff in light and #000 in dark, so the doughnut palette is held to 3:1
 * against both and its data may not switch that border off.
 */
#[CoversNothing]
final class ChartPaletteContrastTest extends AbstractUnitTestCase
{
    private const LIGHT_CARD = '#FFFFFF';

    private const DARK_CARD = '#262626';

    /** Core's doughnut arc border in the light and the dark scheme. */
    private const SEPARATORS = ['#FFFFFF', '#000000'];

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function palettes(): array
    {
        return [
            'agent runs by status' => [AgentRunsByStatusDataProvider::class, 'STATUS_COLORS'],
            'run termination reasons' => [RunTerminationReasonsDataProvider::class, 'REASON_COLORS'],
            'tool denials by reason' => [ToolDenialsByReasonDataProvider::class, 'REASON_COLORS'],
            'governance blocks over time' => [GovernanceBlocksOverTimeDataProvider::class, 'DECISION_COLORS'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('palettes')]
    public function everySeriesColourClearsThreeToOneOnBothCards(string $class, string $constant): void
    {
        $colors = (new ReflectionClassConstant($class, $constant))->getValue();
        self::assertIsArray($colors);
        self::assertNotEmpty($colors);

        // The grey every provider falls back to for a key it does not know.
        $colors['(fallback)'] = '#8E8E8E';

        foreach ($colors as $key => $hex) {
            self::assertIsString($hex);
            foreach ([self::LIGHT_CARD, self::DARK_CARD] as $card) {
                $ratio = $this->contrast($hex, $card);
                self::assertGreaterThanOrEqual(
                    3.0,
                    $ratio,
                    sprintf('%s::%s[%s] = %s is %.2f:1 on %s.', $class, $constant, (string)$key, $hex, $ratio, $card),
                );
            }
        }
    }

    #[Test]
    public function doughnutSegmentsClearThreeToOneAgainstCoresSeparator(): void
    {
        $colors = (new ReflectionClassConstant(AgentRunsByStatusDataProvider::class, 'STATUS_COLORS'))->getValue();
        self::assertIsArray($colors);
        self::assertNotEmpty($colors);
        $colors['(fallback)'] = '#8E8E8E';

        foreach ($colors as $key => $hex) {
            self::assertIsString($hex);
            foreach (self::SEPARATORS as $separator) {
                $ratio = $this->contrast($hex, $separator);
                self::assertGreaterThanOrEqual(3.0, $ratio, sprintf('STATUS_COLORS[%s] = %s is %.2f:1 against the %s separator.', (string)$key, $hex, $ratio, $separator));
            }
        }
    }

    #[Test]
    public function doughnutDataLeavesCoresSegmentSeparatorOn(): void
    {
        $shaped = AgentRunsByStatusDataProvider::shapeChartData(['queued' => 1, 'running' => 2, 'completed' => 3], []);

        self::assertNotEmpty($shaped['datasets']);
        foreach ($shaped['datasets'] as $dataset) {
            self::assertArrayNotHasKey('borderWidth', $dataset, 'The 2 px arc border is the only thing that separates neighbouring segments.');
            self::assertArrayNotHasKey('borderColor', $dataset, 'Core picks the border colour per scheme (#fff light, #000 dark).');
        }
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channel = static function (string $pair): float {
            $v = hexdec($pair) / 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 1, 2))
            + 0.7152 * $channel(substr($hex, 3, 2))
            + 0.0722 * $channel(substr($hex, 5, 2));
    }
}
