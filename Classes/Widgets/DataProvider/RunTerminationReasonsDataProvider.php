<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolvesLanguageLabelTrait;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Chart.js bar-chart data provider for "agent run outcomes (last N days)".
 *
 * Aggregates terminated tx_nrllm_agentrun rows by termination_reason. Grouping
 * by the reason (ADR-092) — not the raw status — is what distinguishes a cost
 * stop (budget_exhausted) from a policy stop (policy_denied / approval_denied)
 * from a provider failure (provider_failed): all three otherwise surface as a
 * completed-but-truncated or failed run. Bars are emitted in
 * {@see AgentRunTerminationReason} case order so the chart is stable.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class RunTerminationReasonsDataProvider implements ChartDataProviderInterface
{
    use ResolvesLanguageLabelTrait;

    private const DEFAULT_DAYS = 30;

    private const LLL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:';

    /** Grey for a key without a colour; held to the same 3:1 on both cards. */
    private const FALLBACK_COLOR = '#8E8E8E';

    /**
     * Semantic colour per termination reason; a missing key falls back to grey.
     *
     * Core's chart widget draws one palette in both backend schemes, so every
     * colour sits in the luminance band that clears 3:1 on a white card and on
     * a dark (#262626) card. The colours are not what tells the bars apart
     * (neighbours are 1.00–1.43:1): each bar has its own axis label and a gap.
     */
    private const REASON_COLORS = [
        'completed'         => '#45A048',
        'max_iterations'    => '#BD830F',
        'budget_exhausted'  => '#BB5715',
        'policy_denied'     => '#DF6A66',
        'approval_denied'   => '#C84179',
        'provider_failed'   => '#9F57B7',
        'cancelled'         => '#8E8E8E',
        'retries_exhausted' => '#936D47',
        'not_retryable'     => '#777739',
        'context_truncated' => '#4078A9',
    ];

    public function __construct(
        private AgentRunRepositoryInterface $repository,
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
            $this->repository->countByTerminationReason($since),
            $this->reasonLabels(),
            $this->resolveLabel(self::LLL . 'widget.run_termination_reasons.dataset'),
        );
    }

    /**
     * Shape the value => count map into chart.js bar format.
     *
     * Pure static for unit-testability, fed pre-resolved labels.
     *
     * @param array<string, int>    $counts       value => count, absent reasons omitted
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
        foreach (AgentRunTerminationReason::cases() as $case) {
            $count = $counts[$case->value] ?? 0;
            if ($count <= 0) {
                continue;
            }

            $chartLabels[] = $labels[$case->value] ?? $case->value;
            $data[]        = $count;
            $colors[]      = self::REASON_COLORS[$case->value] ?? self::FALLBACK_COLOR;
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
     * The translated display label per termination reason, in enum order.
     *
     * @return array<string, string>
     */
    private function reasonLabels(): array
    {
        $labels = [];
        foreach (AgentRunTerminationReason::cases() as $case) {
            $labels[$case->value] = $this->resolveLabel(self::LLL . 'widget.run_termination_reasons.reason.' . $case->value);
        }

        return $labels;
    }
}
