<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolvesLanguageLabelTrait;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Chart.js bar-chart data provider for "governance blocks (last N days)".
 *
 * Aggregates tx_nrllm_governance_event by decision kind — one bar per
 * {@see GovernanceDecision} case, in case order so the chart is stable.
 *
 * The prose deliberately does not enumerate the cases. It did, and drifted
 * twice: `write_unapproved` and `context_blocked` were both added to the enum
 * without this text being touched (`#763`). The enum is the list.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class GovernanceBlocksOverTimeDataProvider implements ChartDataProviderInterface
{
    use ResolvesLanguageLabelTrait;

    private const DEFAULT_DAYS = 30;

    private const LLL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:';

    /** Grey for a key without a colour; held to the same 3:1 on both cards. */
    private const FALLBACK_COLOR = '#8E8E8E';

    /**
     * Semantic colour per decision.
     *
     * Every {@see GovernanceDecision} case must have one — asserted by
     * {@see \Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider\GovernanceBlocksOverTimeDataProviderTest}.
     * The grey FALLBACK_COLOR stays for a case added at runtime by another
     * extension, not as a licence to leave one out here: `context_blocked`
     * shipped without a colour and rendered as an unnamed grey bar (`#763`).
     *
     * Core's chart widget draws one palette in both backend schemes, so every
     * colour sits in the luminance band that clears 3:1 on a white card and on
     * a dark (#262626) card. The colours are not what tells the bars apart
     * (neighbours are 1.00–1.43:1): each bar has its own axis label and a gap.
     */
    private const DECISION_COLORS = [
        'tool_denied' => '#7692A0',
        'response_blocked' => '#DF6A66',
        'approval_required' => '#BD830F',
        'content_filter' => '#C84179',
        'write_unapproved' => '#8D5FBB',
        'context_blocked' => '#178277',
        'invocation_denied' => '#597FAB',
    ];

    public function __construct(
        private GovernanceEventRepositoryInterface $repository,
        private int $days = self::DEFAULT_DAYS,
    ) {}

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, backgroundColor: list<string>, data: list<int>}>}
     */
    public function getChartData(): array
    {
        $since = (new DateTimeImmutable())
            ->modify(sprintf('-%d days', max(1, $this->days)))
            ->getTimestamp();

        return self::shapeChartData(
            $this->repository->countByDecision($since),
            $this->decisionLabels(),
            $this->resolveLabel(self::LLL . 'widget.governance_blocks.dataset'),
        );
    }

    /**
     * Pure static for unit-testability, fed pre-resolved labels.
     *
     * @param array<string, int>    $counts       value => count, absent decisions omitted
     * @param array<string, string> $labels       value => translated display label
     * @param string                $datasetLabel translated legend for the single dataset
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, backgroundColor: list<string>, data: list<int>}>}
     */
    public static function shapeChartData(array $counts, array $labels, string $datasetLabel): array
    {
        $chartLabels = [];
        $data        = [];
        $colors      = [];
        foreach (GovernanceDecision::cases() as $case) {
            $count = $counts[$case->value] ?? 0;
            if ($count <= 0) {
                continue;
            }

            $chartLabels[] = $labels[$case->value] ?? $case->value;
            $data[]        = $count;
            $colors[]      = self::DECISION_COLORS[$case->value] ?? self::FALLBACK_COLOR;
        }

        return [
            'labels'   => $chartLabels,
            'datasets' => [
                [
                    'label'           => $datasetLabel,
                    'backgroundColor' => $colors,
                    'data'            => $data,
                ],
            ],
        ];
    }

    /**
     * The translated display label per decision, in enum order.
     *
     * @return array<string, string>
     */
    private function decisionLabels(): array
    {
        $labels = [];
        foreach (GovernanceDecision::cases() as $case) {
            $labels[$case->value] = $this->resolveLabel(self::LLL . 'widget.governance_blocks.decision.' . $case->value);
        }

        return $labels;
    }
}
