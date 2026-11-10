<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * `AgentCapabilities` (proto). An absent flag is null, not false: the proto
 * marks all three `optional`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aAgentCapabilities
{
    public function __construct(
        public ?bool $streaming = null,
        public ?bool $pushNotifications = null,
        public ?bool $extendedAgentCard = null,
    ) {}

    /**
     * A stable comparison form; a changed value means the operator has to
     * confirm the record again (A2A-C2).
     */
    public function fingerprint(): string
    {
        $flag = static fn (?bool $value): string => $value === null ? '-' : ($value ? '1' : '0');

        return sprintf(
            'streaming=%s;pushNotifications=%s;extendedAgentCard=%s',
            $flag($this->streaming),
            $flag($this->pushNotifications),
            $flag($this->extendedAgentCard),
        );
    }
}
