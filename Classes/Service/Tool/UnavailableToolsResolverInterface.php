<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The tools that exist in this installation but will not be offered to a run
 * of this configuration for this user, each with the gate that holds it back
 * (ADR-201).
 *
 * A model only sees the tools it is offered. Asked for something one of the
 * others would do, it can only say that no such tool exists — which is wrong,
 * and sends the user nowhere. With this list a consumer can tell the model
 * which capability exists and why it is not available here, so the answer can
 * be "there is a tool for that, it is not enabled for you — ask an
 * administrator".
 *
 * The decision per tool is {@see ToolCallPolicyInterface::decide()}; this only
 * runs it over every registered tool rather than over the enabled ones, so a
 * disabled tool is reported too. A builtin tool the trust-zone gate would
 * refuse while enforcement is in observe mode IS offered, and is therefore not
 * listed. A remote tool (an MCP server's) is always enforced by that gate, in
 * observe mode too ({@see ToolCallPolicy::decide()}), so when the ceiling
 * refuses it, it is listed with `trustZone` in either mode.
 *
 * Remote tools are listed for administrators only: their names come from
 * operator configuration, not from this extension, and an editor is shown
 * builtin tools alone.
 *
 * @api
 */
interface UnavailableToolsResolverInterface
{
    /**
     * Every registered tool the gate refuses, in registry order.
     *
     * @return list<ToolPolicyDecision> only decisions with `allowed === false`
     */
    public function unavailable(LlmConfiguration $configuration, ?BackendUserAuthentication $user): array;
}
