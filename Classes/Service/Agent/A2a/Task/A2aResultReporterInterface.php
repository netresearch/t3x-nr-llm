<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;

/**
 * Adds a report artifact to a task's result (ADR-301).
 *
 * Called by {@see A2aTaskStore} inside the transaction that completes or
 * fails the task, under the run-row lock, with the answer level read under
 * that lock. It reads stored records only; it never calls a model.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface A2aResultReporterInterface
{
    /**
     * The report artifact of a completed task, or null when the task's
     * handler reports nothing.
     *
     * @param list<array<string, mixed>> $contentArtifacts the artifacts the task completes with, A2A JSON
     *
     * @return array<string, mixed>|null
     */
    public function completed(int $runUid, int $profileUid, array $contentArtifacts, PrivacyLevel $ceiling): ?array;

    /**
     * The report artifact of a task its end-of-run check stopped, or null
     * when the run was not stopped by that check.
     *
     * @return array<string, mixed>|null
     */
    public function stopped(int $runUid, int $profileUid, PrivacyLevel $ceiling, string $terminationReason): ?array;
}
