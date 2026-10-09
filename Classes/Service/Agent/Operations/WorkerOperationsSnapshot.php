<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Operations;

/**
 * @internal Aggregate observations, without run payloads (ADR-219).
 */
final readonly class WorkerOperationsSnapshot
{
    public function __construct(
        public int $queued,
        public int $running,
        public int $deadLettered,
        public int $expiredLeases,
        public ?int $oldestQueueWaitSeconds,
        public int $unknownQueueWait,
        public int $liveWorkers,
    ) {}

    /**
     * @return array<string,int|null>
     */
    public function toArray(): array
    {
        return [
            'queued' => $this->queued,
            'running' => $this->running,
            'dead_lettered' => $this->deadLettered,
            'expired_leases' => $this->expiredLeases,
            'oldest_queue_wait_seconds' => $this->oldestQueueWaitSeconds,
            'unknown_queue_wait' => $this->unknownQueueWait,
            'live_workers' => $this->liveWorkers,
        ];
    }
}
