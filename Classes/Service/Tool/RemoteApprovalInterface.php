<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * A remote tool that carries an operator's answer to "must a human approve
 * this?" (ADR-134).
 *
 * {@see RequiresApprovalInterface} is a marker: implementing it means yes,
 * always. This one is a declaration and can say no, which is why it exists
 * separately — the remote half of the approval rule is not a property of code
 * anybody here can read, so it has to be able to carry either answer.
 *
 * It extends {@see RemoteToolInterface} deliberately. ADR-134 couples a
 * BUILTIN's declared write effect to approval and states that a
 * write-without-approval builtin is not expressible on purpose; a general
 * "declare your own approval" interface would quietly make it expressible
 * again. Extending the remote marker means a class cannot reach for this
 * declaration without also claiming its behaviour lives outside this codebase —
 * the one situation in which an operator declaration is the better source than
 * the code.
 *
 * The answer never comes from the server. It is the operator-set
 * `tx_nrllm_mcp_server.requires_approval` column, for the same reason the data
 * class is operator-set: a remote server must not be able to influence its own
 * authorisation, which is why the `readOnlyHint` annotation it sends is stored
 * for display and read by nobody
 * (see {@see \Netresearch\NrLlm\Domain\ValueObject\McpToolRecord}).
 */
interface RemoteApprovalInterface extends RemoteToolInterface
{
    /**
     * Whether the agent loop must suspend for a human before executing this
     * tool. Fail-closed: an implementation that cannot read its declaration
     * returns true.
     */
    public function requiresApproval(): bool;
}
