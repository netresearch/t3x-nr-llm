<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

/**
 * Turns the kept answer of a task's run into the task's artifacts, by the
 * domain handler of the task's publication profile (spec 051 A2A-SV4).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface A2aArtifactShaperInterface
{
    /**
     * @return list<array<string, mixed>>|null A2A artifacts; null leaves the store's default of one text artifact
     */
    public function artifactsFor(int $profileUid, string $keptAnswer): ?array;
}
