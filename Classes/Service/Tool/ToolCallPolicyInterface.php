<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
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
 * 4. it is within the run's skill allow-list (ADR-038 item 5) and its group
 *    within the configuration's allowed tool groups;
 * 5. its data class is within the ceiling of the trust zone the run can reach.
 *
 * Consumers ask this rather than re-deriving the rules, so a new entry point
 * cannot accidentally ship with four of the five.
 *
 * Gate 4 takes the run's skill allow-list when the caller has a run: resolved
 * once at run start by {@see self::skillAllowListForRun()}, stored with the
 * run, and passed back on every decision. A caller with no run passes none and
 * gets the configuration-only list — the configuration's attached skills, no
 * forced ones.
 *
 * @api
 */
interface ToolCallPolicyInterface
{
    /**
     * Evaluate every gate for one tool and report the outcome, including why it
     * was denied.
     */
    public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): ToolPolicyDecision;

    /**
     * The tools that may be offered to a run.
     *
     * @param list<string>|null $requested the caller's request; null means "no per-run restriction"
     *
     * @return list<string>
     */
    public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): array;

    /**
     * The decisions for every tool the caller asked for, allowed or not — the
     * material a UI needs to explain an absence.
     *
     * @param list<string>|null $requested
     *
     * @return list<ToolPolicyDecision>
     */
    public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): array;

    /**
     * The skill allow-list of a run that starts now: the union of the
     * `allowed-tools` declarations over the configuration's attached skills
     * and the run's forced skills (ADR-038 item 5). The caller stores it with
     * the run and passes it to every decision for that run.
     *
     * @param list<Skill> $forcedSkills
     */
    public function skillAllowListForRun(LlmConfiguration $configuration, array $forcedSkills = []): SkillToolAllowList;
}
