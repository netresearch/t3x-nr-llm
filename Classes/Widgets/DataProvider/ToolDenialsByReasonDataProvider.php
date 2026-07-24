<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolvesLanguageLabelTrait;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Chart.js bar-chart data provider for "tool denials by reason (last N days)".
 *
 * Aggregates tx_nrllm_governance_event rows with decision = tool_denied by
 * their {@see ToolDenialReason}. Bars are emitted in enum order so the chart is
 * stable; the NONE case never carries a count (a denial always has a reason)
 * and is skipped with every other zero-count reason.
 */
final readonly class ToolDenialsByReasonDataProvider implements ChartDataProviderInterface
{
    use ResolvesLanguageLabelTrait;

    private const DEFAULT_DAYS = 30;

    private const LLL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_dashboard.xlf:';

    /** Semantic colour per denial reason; a missing key falls back to grey. */
    private const REASON_COLORS = [
        'notRegistered'      => '#607D8B',
        'toolDisabled'       => '#9E9E9E',
        'requiresAdmin'      => '#D9534F',
        'configurationGroup' => '#E8A33D',
        'trustZone'          => '#8E2A27',
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
            $this->repository->countToolDenialsByReason($since),
            $this->reasonLabels(),
            $this->resolveLabel(self::LLL . 'widget.tool_denials.dataset'),
        );
    }

    /**
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
        foreach (ToolDenialReason::cases() as $case) {
            // NONE is the "not denied" case; it never carries a denial count, and
            // a "denials by reason" chart must never show it even if a stray row
            // did.
            if ($case === ToolDenialReason::NONE) {
                continue;
            }
            $count = $counts[$case->value] ?? 0;
            if ($count <= 0) {
                continue;
            }
            $chartLabels[] = $labels[$case->value] ?? $case->value;
            $data[]        = $count;
            $colors[]      = self::REASON_COLORS[$case->value] ?? '#9E9E9E';
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
     * The translated display label per denial reason, in enum order.
     *
     * @return array<string, string>
     */
    private function reasonLabels(): array
    {
        $labels = [];
        foreach (ToolDenialReason::cases() as $case) {
            $labels[$case->value] = $this->resolveLabel(self::LLL . 'widget.tool_denials.reason.' . $case->value);
        }

        return $labels;
    }
}
