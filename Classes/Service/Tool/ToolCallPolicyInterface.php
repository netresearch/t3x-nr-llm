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
 * The single place that decides whether a tool may take part in a run
 * (ADR-094).
 *
 * Five gates, evaluated as one AND:
 *
 * 1. the tool is registered;
 * 2. it and its group are globally enabled;
 * 3. the acting user may use it (admin-only tools need an admin);
 * 4. its group is within the configuration's allowed tool groups;
 * 5. its data class is within the ceiling of the trust zone the run can reach.
 *
 * Consumers ask this rather than re-deriving the rules, so a new entry point
 * cannot accidentally ship with four of the five.
 */
interface ToolCallPolicyInterface
{
    /**
     * Evaluate every gate for one tool and report the outcome, including why it
     * was denied.
     */
    public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user): ToolPolicyDecision;

    /**
     * The tools that may be offered to a run.
     *
     * @param list<string>|null $requested the caller's request; null means "no per-run restriction"
     *
     * @return list<string>
     */
    public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array;

    /**
     * The decisions for every tool the caller asked for, allowed or not — the
     * material a UI needs to explain an absence.
     *
     * @param list<string>|null $requested
     *
     * @return list<ToolPolicyDecision>
     */
    public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array;
}
