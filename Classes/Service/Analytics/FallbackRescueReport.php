<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Telemetry\FallbackHop;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;

/**
 * The runs a sibling configuration answered for — the reader of the
 * `served_*` telemetry columns.
 *
 * The repository narrows to runs a sibling served (time-bounded, newest
 * first) — `fallback_attempts > 0` alone would also match a chain that was
 * exhausted with nobody serving, and a row written before the `served_*`
 * columns existed carries '' for them. That narrowing has to happen in the
 * query because the scan limit below is applied there. What a RESCUE IS is
 * nevertheless stated here, on the one field that can tell:
 *
 *  - the served identifier must be present ('' means "row predates the
 *    columns" — it must not be read as a swap to a nameless configuration);
 *  - it must differ from the requested one (equal means the requested
 *    configuration answered, or nothing did — `fallback_attempts` alone would
 *    have counted both of those as rescues, which is the defect this list
 *    exists to make visible).
 *
 * The outcome is kept on the row rather than filtered out: on the streaming
 * path a sibling can open the stream and the stream still break while draining,
 * which is a swap that happened and is worth seeing.
 */
final readonly class FallbackRescueReport
{
    /**
     * How many rescues are read back at most. A rescue is by nature rare, so a
     * window this size covers a normal period; a period with more shows the
     * newest ones, and the primary they requested is the thing to fix first
     * anyway. The limit counts rescues, not fallback attempts — an exhausted
     * chain repeating through an outage must not push a rescue out of the list
     * and turn the module's empty state into a false "none in this period".
     */
    private const HOP_SCAN_LIMIT = 200;

    public function __construct(
        private TelemetryRepositoryInterface $telemetry,
    ) {}

    /**
     * The rescues from $from until now, newest first.
     *
     * There is no upper bound: every {@see AnalyticsPeriod} preset ends at the
     * current day, so an explicit `to` would be a parameter that always says
     * "now" — and one whose midnight anchoring (the usage table's day buckets)
     * would silently cut today's per-second telemetry rows.
     *
     * @return list<array{
     *     when: int,
     *     operation: string,
     *     requestedConfiguration: string,
     *     requestedProvider: string,
     *     requestedModel: string,
     *     servedConfiguration: string,
     *     servedProvider: string,
     *     servedModel: string,
     *     fallbackAttempts: int,
     *     latencyMs: int,
     *     success: bool
     * }>
     */
    public function rescuesSince(DateTimeImmutable $from): array
    {
        $rescues = [];
        foreach ($this->telemetry->recentFallbackHops($from->getTimestamp(), self::HOP_SCAN_LIMIT) as $hop) {
            if (!$this->isRescue($hop)) {
                continue;
            }

            $rescues[] = [
                'when'                   => $hop->crdate,
                'operation'              => $hop->operation,
                'requestedConfiguration' => $hop->configurationIdentifier,
                'requestedProvider'      => $hop->provider,
                'requestedModel'         => $hop->model,
                'servedConfiguration'    => $hop->servedConfigurationIdentifier,
                'servedProvider'         => $hop->servedProvider,
                'servedModel'            => $hop->servedModel,
                'fallbackAttempts'       => $hop->fallbackAttempts,
                'latencyMs'              => $hop->latencyMs,
                'success'                => $hop->success,
            ];
        }

        return $rescues;
    }

    private function isRescue(FallbackHop $hop): bool
    {
        return $hop->servedConfigurationIdentifier !== ''
            && $hop->servedConfigurationIdentifier !== $hop->configurationIdentifier;
    }
}
