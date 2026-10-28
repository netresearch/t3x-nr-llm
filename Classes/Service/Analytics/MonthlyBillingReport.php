<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use DateTimeImmutable;

/**
 * The monthly billing report: what the page shows and the CSV exports.
 *
 * Built by {@see MonthlyBillingReportService}. The usage figures cover the
 * month; the entitled editors are the state at `entitlementAsOf` (ADR-231).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class MonthlyBillingReport
{
    /**
     * @param list<array{uid: int, username: string, realName: string, admin: bool, disabled: bool, deleted: bool, cost: float, requests: int, tokens: int}> $activeEditors   backend users with at least one request in the month, by cost
     * @param array{cost: float, requests: int, tokens: int}|null                                                                                            $withoutUser     usage recorded without a backend user (CLI, scheduler); null when there was none
     * @param list<array{uid: int, username: string, realName: string}>                                                                                      $entitledEditors
     * @param list<array{serviceType: string, sourceExtension: string, taskUid: int, taskName: string, cost: float, requests: int, tokens: int}>             $byKind
     */
    public function __construct(
        public BillingMonth $month,
        public bool $monthIsRunning,
        public int $requests,
        public int $tokens,
        public BillingAmounts $amounts,
        public bool $markupAdjusted,
        public array $activeEditors,
        public ?array $withoutUser,
        public array $entitledEditors,
        public int $administratorCount,
        public DateTimeImmutable $entitlementAsOf,
        public array $byKind,
    ) {}

    public function getActiveEditorCount(): int
    {
        return count($this->activeEditors);
    }

    public function getEntitledEditorCount(): int
    {
        return count($this->entitledEditors);
    }
}
