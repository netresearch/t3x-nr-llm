<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why a tool was not offered to a run (ADR-094).
 *
 * Reported in the order the gates are evaluated — cheapest and least revealing
 * first — so a caller who was already blocked by enablement never learns that a
 * trust-zone axis exists.
 */
enum ToolDenialReason: string
{
    case NONE = 'none';

    case NOT_REGISTERED = 'notRegistered';

    case TOOL_DISABLED = 'toolDisabled';

    case REQUIRES_ADMIN = 'requiresAdmin';

    case CONFIGURATION_GROUP = 'configurationGroup';

    case TRUST_ZONE = 'trustZone';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
