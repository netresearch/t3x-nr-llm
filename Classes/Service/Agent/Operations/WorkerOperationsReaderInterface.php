<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Operations;

/**
 * @internal Aggregate status query for the operations command.
 */
interface WorkerOperationsReaderInterface
{
    public function snapshot(
        int $now,
        int $workerMaxAge = 120,
        string $transport = 'doctrine',
    ): WorkerOperationsSnapshot;
}
