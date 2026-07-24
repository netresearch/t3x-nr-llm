<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolvesLanguageLabelTrait;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Chart.js doughnut data provider for "agent runs by status (last N days)".
 *
 * Aggregates tx_nrllm_agentrun by lifecycle status. Slices are emitted in
 * {@see AgentRunStatus} case order so the doughnut is stable regardless of which
 * statuses happen to be present, each labelled via XLIFF and coloured by a fixed
 * semantic map (terminal green/red/grey, waiting amber, running/queued blue).
 */
final readonly class AgentRunsByStatusDataProvider implements ChartDataProviderInterface
{
    use ResolvesLanguageLabelTrait;

    private const DEFAULT_DAYS = 30;

    private const LLL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:';

    /** Semantic colour per status value; a missing key falls back to grey. */
    private const STATUS_COLORS = [
        'queued'               => '#6DAEDB',
        'running'              => '#2F99A4',
        'waiting_for_approval' => '#E8A33D',
        'waiting_for_input'    => '#E8C33D',
        'completed'            => '#4CAF50',
        'failed'               => '#D9534F',
        'cancelled'            => '#9E9E9E',
    ];

    public function __construct(
        private AgentRunRepositoryInterface $repository,
        private int $days = self::DEFAULT_DAYS,
    ) {}

    /**
     * @return array{labels: list<string>, datasets: list<array{backgroundColor: list<string>, data: list<int>}>}
     */
    public function getChartData(): array
    {
        $since = (new DateTimeImmutable())
            ->modify(sprintf('-%d days', max(1, $this->days)))
            ->getTimestamp();

        return self::shapeChartData($this->repository->countByStatus($since), $this->statusLabels());
    }

    /**
     * Shape the value => count map into chart.js doughnut format.
     *
     * Extracted as a pure static for unit-testability — the ConnectionPool query
     * path and the LanguageService lookup are covered by functional/backend
     * context, this is fed pre-resolved labels.
     *
     * @param array<string, int>    $counts value => count, absent statuses omitted
     * @param array<string, string> $labels value => translated display label
     *
     * @return array{labels: list<string>, datasets: list<array{backgroundColor: list<string>, data: list<int>}>}
     */
    public static function shapeChartData(array $counts, array $labels): array
    {
        $chartLabels = [];
        $data        = [];
        $colors      = [];
        foreach (AgentRunStatus::cases() as $case) {
            $count = $counts[$case->value] ?? 0;
            if ($count <= 0) {
                continue;
            }
            $chartLabels[] = $labels[$case->value] ?? $case->value;
            $data[]        = $count;
            $colors[]      = self::STATUS_COLORS[$case->value] ?? '#9E9E9E';
        }

        return [
            'labels'   => $chartLabels,
            'datasets' => [
                [
                    'backgroundColor' => $colors,
                    'data'            => $data,
                ],
            ],
        ];
    }

    /**
     * The translated display label per status value, in enum order.
     *
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        $labels = [];
        foreach (AgentRunStatus::cases() as $case) {
            $labels[$case->value] = $this->resolveLabel(self::LLL . 'widget.agent_runs_by_status.status.' . $case->value);
        }

        return $labels;
    }
}
