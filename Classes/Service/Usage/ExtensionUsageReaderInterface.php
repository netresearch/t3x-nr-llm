<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Usage;

use DateTimeInterface;
use Netresearch\NrLlm\Domain\ValueObject\ExtensionUsageTotals;
use Netresearch\NrLlm\Domain\ValueObject\OperationUsage;
use Netresearch\NrLlm\Domain\ValueObject\TelemetryCoverage;

/**
 * What one calling extension spent and did, read from the two places
 * nr_llm records it (ADR-248).
 *
 * A consuming extension names itself on its calls with
 * `withCallerSource($extensionKey, $operation)` (ADR-177, ADR-178). This
 * reader answers for that name:
 *
 * - {@see totals()}: cost, requests and tokens from the daily usage
 *   aggregate, the figures the Analytics module shows per extension. The
 *   aggregate is not purged by the telemetry retention.
 * - {@see operations()}: calls and failed calls per operation, from the
 *   per-call telemetry. Telemetry is purged after its retention window and
 *   can be switched off, so {@see coverage()} says from when the counts are
 *   complete.
 *
 * Passing a backend user uid narrows every figure to that user, which is how
 * an editor is shown their own usage. Passing none returns the installation's
 * figures; whether the viewer may see those is the caller's decision.
 *
 * @api
 */
interface ExtensionUsageReaderInterface
{
    /**
     * The aggregate counts whole days: the day `$from` falls on is included
     * entirely, and so is every day that starts before `$to`. Both bounds are
     * inclusive. Requests are the calls the usage tracker counts as a request,
     * so they need not equal the calls {@see operations()} counts.
     */
    public function totals(
        string $sourceExtension,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $beUserUid = null,
    ): ExtensionUsageTotals;

    /**
     * One entry per operation the extension named, most calls first.
     *
     * @return list<OperationUsage>
     */
    public function operations(
        string $sourceExtension,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $beUserUid = null,
    ): array;

    /**
     * The oldest stored call is a lower bound: a stretch while telemetry was
     * switched off leaves a gap after it that this cannot see.
     */
    public function coverage(): TelemetryCoverage;
}
