<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * A `ListTasksResponse` (proto `ListTasksResponse`).
 *
 * `nextPageToken` is `''` on the last page, as the proto says; a ProtoJSON
 * serialiser leaves an empty string out, which reads as `''` here too.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTaskList
{
    /**
     * @param list<A2aTask> $tasks
     */
    public function __construct(
        public array $tasks,
        public string $nextPageToken,
        public int $pageSize,
        public int $totalSize,
    ) {}
}
