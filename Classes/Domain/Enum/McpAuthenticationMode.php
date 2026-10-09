<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The operator-selected authentication strategy (ADR-217).
 *
 * @api
 */
enum McpAuthenticationMode: string
{
    case LEGACY = 'legacy';
    case DELEGATED = 'delegated';
}
