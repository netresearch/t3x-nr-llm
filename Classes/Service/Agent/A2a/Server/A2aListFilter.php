<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;

/**
 * The filter of a `ListTasks` call (spec 051 A2A-SV6): context, state and
 * the earliest status timestamp in milliseconds.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aListFilter
{
    public function __construct(
        public string $contextId = '',
        public ?A2aTaskState $state = null,
        public ?int $statusTimestampAfter = null,
        public bool $includeArtifacts = false,
    ) {}

    /**
     * What a cursor is bound to.
     */
    public function fingerprint(): string
    {
        return json_encode([$this->contextId, $this->state?->value, $this->statusTimestampAfter, $this->includeArtifacts], JSON_THROW_ON_ERROR);
    }
}
