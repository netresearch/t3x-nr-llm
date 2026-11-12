<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Agent;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aAgentCard;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aAgentInterface;

/**
 * What a card import stored (spec 051 A2A-C2). `$confirmationRequired` is
 * true when the record now waits for an operator to save it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aCardImportResult
{
    public function __construct(
        public A2aAgentCard $card,
        public A2aAgentInterface $interface,
        public bool $confirmationRequired,
    ) {}
}
