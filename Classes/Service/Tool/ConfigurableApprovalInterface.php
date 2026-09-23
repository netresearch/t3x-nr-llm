<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * A READ-ONLY builtin whose need for a human approval depends on the
 * operator's configuration (ADR-202).
 *
 * {@see RequiresApprovalInterface} says "always"; this says "unless the
 * operator configured the condition under which it is safe". It exists for
 * `fetch_external_url`, whose risk is not a write but the URL itself carrying
 * data out: approval is on by default and can be switched off only together
 * with a host allowlist.
 *
 * It cannot switch approval off for a write. {@see ToolApprovalRule} asks the
 * declared write effect first, so a tool declaring a write effect is approved
 * whatever this returns — ADR-134's "a write-without-approval builtin is not
 * expressible" stays true.
 *
 * @internal
 */
interface ConfigurableApprovalInterface
{
    /**
     * Whether the agent loop must suspend for a human before executing this
     * tool now. Fail-closed: an implementation that cannot read its
     * configuration returns true.
     */
    public function requiresApproval(): bool;
}
