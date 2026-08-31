<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Service\Outcome\CallOutcomeRepositoryInterface;

/**
 * An outcome repository that keeps what it was told, so a test can assert on
 * it.
 *
 * A named class rather than an anonymous one returned as the interface: the
 * anonymous form loses its own members to static analysis the moment it is
 * handed back under the interface type, and a test that reaches for
 * `$recorded` then fails the PHPStan leg rather than the assertion.
 *
 * It keeps what it was told PER CORRELATION as well as in order. A double that
 * answered the same for every id could not represent two runs in different
 * states, so a batch test written against it would pass whatever the batch
 * logic did.
 */
final class RecordingCallOutcomeRepository implements CallOutcomeRepositoryInterface
{
    /** @var list<CallOutcome> */
    public array $recorded = [];

    /** @var array<string, list<CallOutcome>> */
    public array $recordedBy = [];

    /**
     * @param array<string, list<CallOutcome>> $existing what a previous derivation already wrote, per correlation
     */
    public function __construct(private readonly array $existing = []) {}

    public function record(string $correlationId, CallOutcome $outcome): void
    {
        $this->recorded[]                   = $outcome;
        $this->recordedBy[$correlationId][] = $outcome;
    }

    public function findByCorrelation(string $correlationId): array
    {
        return $this->existing[$correlationId] ?? [];
    }

    public function purgeOlderThan(int $timestamp): int
    {
        return 0;
    }
}
