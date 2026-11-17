<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What an administrator switched in the Tools module, as recorded in the
 * tool audit trail (`tx_nrllm_tool_audit`).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum ToolAuditScope: string
{
    /**
     * One tool, by its name.
     */
    case TOOL = 'tool';

    /**
     * A tool group, by its name; it switches every tool of the group.
     */
    case GROUP = 'group';
}
