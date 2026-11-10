<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * `Role` of the pinned proto, by its proto name (A2A-P3).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum A2aRole: string
{
    case UNSPECIFIED = 'ROLE_UNSPECIFIED';
    case USER        = 'ROLE_USER';
    case AGENT       = 'ROLE_AGENT';
}
