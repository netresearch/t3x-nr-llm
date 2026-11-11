<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Exception;

/**
 * The remote agent record may not be used as it stands (spec 051 A2A-C1,
 * A2A-C2): no data class, disabled, no imported card, or a changed card not
 * yet confirmed. Nothing was journalled or sent.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aAgentUnavailableException extends A2aException
{
    public static function because(string $refusal): self
    {
        return new self(ucfirst($refusal) . '.', 1799100400);
    }
}
