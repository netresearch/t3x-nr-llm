<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolvesLanguageLabelTrait;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Chart.js bar-chart data provider for "tool gate decisions by tool
 * (last N days)".
 *
 * Aggregates tx_nrllm_governance_event rows carrying a tool_name, grouped by
 * tool, most-decided first.
 *
 * Scope honesty: this table records tool DENIALS / gate decisions by name — a
 * truthful "which tools get blocked, and how often" view. It is NOT
 * successful-invocation volume; genuine successful tool usage still lives in
 * tx_nrllm_agentrun_event (kind = tool, name inside the payload). The widget is
 * labelled accordingly so the two are never conflated.
 */
final readonly class ToolUsageByNameDataProvider implements ChartDataProviderInterface
{
    use ResolvesLanguageLabelTrait;

    private const DEFAULT_DAYS = 30;

    private const LLL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:';

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
            $this->repository->countToolDecisionsByName($since),
            $this->resolveLabel(self::LLL . 'widget.tool_usage.dataset'),
        );
    }

    /**
     * Shape the tool_name => count map (already ordered most-decided first by the
     * repository) into chart.js bar format. Tool names are their own labels —
     * they are dynamic identifiers, not a fixed enum, so there is nothing to
     * translate. Pure static for unit-testability.
     *
     * @param array<string, int> $counts       tool_name => count
     * @param string             $datasetLabel translated legend for the single dataset
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, backgroundColor: list<string>, data: list<int>}>}
     */
    public static function shapeChartData(array $counts, string $datasetLabel): array
    {
        $labels = [];
        $data   = [];
        foreach ($counts as $toolName => $count) {
            if ($toolName === '' || $count <= 0) {
                continue;
            }
            $labels[] = $toolName;
            $data[]   = $count;
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                [
                    'label'           => $datasetLabel,
                    'backgroundColor' => array_fill(0, count($data), '#2F99A4'),
                    'data'            => $data,
                ],
            ],
        ];
    }
}
