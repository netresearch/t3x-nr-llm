<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Language\LabelCatalogue;
use Netresearch\NrLlm\Widgets\DataProvider\GovernanceBlocksOverTimeDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GovernanceBlocksOverTimeDataProvider::class)]
final class GovernanceBlocksOverTimeDataProviderTest extends AbstractUnitTestCase
{
    /** @var array<string, string> */
    private const LABELS = [
        'tool_denied'       => 'Tool denied',
        'response_blocked'  => 'Response blocked',
        'approval_required' => 'Approval required',
        'content_filter'    => 'Content filter',
    ];

    #[Test]
    public function emitsBarsInEnumOrderWithColoursAndDatasetLabel(): void
    {
        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData([
            'content_filter'   => 2,
            'tool_denied'      => 9,
            'response_blocked' => 3,
        ], self::LABELS, 'Events');

        self::assertSame(['Tool denied', 'Response blocked', 'Content filter'], $shaped['labels']);
        self::assertSame('Events', $shaped['datasets'][0]['label']);
        self::assertSame([9, 3, 2], $shaped['datasets'][0]['data']);
        self::assertSame(['#607D8B', '#D9534F', '#8E2A27'], $shaped['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function skipsZeroCounts(): void
    {
        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData([
            'tool_denied'       => 0,
            'approval_required' => 4,
        ], self::LABELS, 'Events');

        self::assertSame(['Approval required'], $shaped['labels']);
        self::assertSame([4], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function fallsBackToRawDecisionValueWhenLabelMissing(): void
    {
        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData([
            'tool_denied' => 1,
        ], [], 'Events');

        self::assertSame(['tool_denied'], $shaped['labels']);
    }

    #[Test]
    public function returnsEmptyStructureForNoCounts(): void
    {
        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData([], self::LABELS, 'Events');

        self::assertSame([], $shaped['labels']);
        self::assertSame([], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function everyDecisionHasItsOwnColour(): void
    {
        // #763: `context_blocked` was added to the enum and the widget was not
        // touched, so it rendered as the grey fallback — indistinguishable from
        // any other unmapped case. Nothing failed. Asserted through the public
        // shaping rather than by reading the private constant, so the check is
        // about what the chart shows.
        $everyCase = [];
        foreach (GovernanceDecision::cases() as $case) {
            $everyCase[$case->value] = 1;
        }

        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData($everyCase, [], 'Events');
        $colors = $shaped['datasets'][0]['backgroundColor'];

        self::assertCount(count(GovernanceDecision::cases()), $colors);
        self::assertNotContains('#9E9E9E', $colors, 'A decision fell through to the grey fallback — give it a colour in DECISION_COLORS.');
        self::assertSame($colors, array_unique($colors), 'Two decisions share a colour, so the chart cannot tell them apart.');
    }

    #[Test]
    #[DataProvider('decisionCases')]
    public function everyDecisionHasALabelInBothCatalogues(string $value): void
    {
        // The other half of #763: without a trans-unit the bar is labelled with
        // the raw LLL: path, because ResolvesLanguageLabelTrait returns its
        // argument unchanged when the key does not resolve.
        $key = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:widget.governance_blocks.decision.' . $value;

        self::assertNotNull(LabelCatalogue::source($key), 'No English label for ' . $value);
        self::assertNotNull(LabelCatalogue::target($key), 'No German label for ' . $value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decisionCases(): array
    {
        $cases = [];
        foreach (GovernanceDecision::cases() as $case) {
            $cases[$case->value] = [$case->value];
        }

        return $cases;
    }
}
