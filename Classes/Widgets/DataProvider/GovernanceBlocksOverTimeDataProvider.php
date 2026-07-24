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
 * Aggregates tx_nrllm_governance_event by decision kind: tool-gate denials,
 * guardrail response blocks, approval-required routings and provider
 * content-filter blocks — the outcomes that were previously only logged or
 * reflected on a run. Bars are emitted in {@see GovernanceDecision} case order
 * so the chart is stable.
 */
final readonly class GovernanceBlocksOverTimeDataProvider implements ChartDataProviderInterface
{
    use ResolvesLanguageLabelTrait;

    private const DEFAULT_DAYS = 30;

    private const LLL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:';

    /** Semantic colour per decision; a missing key falls back to grey. */
    private const DECISION_COLORS = [
        'tool_denied'       => '#607D8B',
        'response_blocked'  => '#D9534F',
        'approval_required' => '#E8A33D',
        'content_filter'    => '#8E2A27',
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
            $colors[]      = self::DECISION_COLORS[$case->value] ?? '#9E9E9E';
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
