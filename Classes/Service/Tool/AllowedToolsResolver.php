<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Service\Skill\SkillComposer;

/**
 * Resolves the effective allowed-tools allow-list for a run.
 *
 * Semantics (fail-closed on declaration): the allow-list is the UNION of the
 * declared lists of every admitted skill (config + task, enabled, non-orphaned,
 * trust-gated — {@see SkillComposer::admittedSkills()}). Unlike the injection
 * path's {@see SkillComposer::effectiveSkills()}, a (source, identifier) twin
 * is not deduped away here, so it cannot drop a restriction. A skill that
 * declares no `allowed-tools` key (its accessor returns null) contributes no
 * opinion. When NO effective skill declares anything, this returns null meaning
 * "no skill-imposed restriction" (all registry tools are permitted). When at least
 * one skill declares, the union is returned — and a lone declared empty list yields
 * `[]`, i.e. no tools at all.
 *
 * The union covers the injection path's selection, but not necessarily the
 * same SET that reaches the prompt: the skill-block byte budget is applied later,
 * in {@see SkillComposer::composeBlock()}, so a budget-dropped skill still grants
 * its tools while its prose does not ship (ADR-036 §5, ADR-038 §5). Counting the
 * budget here is deliberately NOT done — dropping the last declaring skill would
 * make this return null ("no restriction", i.e. every registered tool), so a
 * tighter budget would widen the gate instead of narrowing it.
 *
 * Two entry points. {@see self::resolveForRun()} is the run's list: taken over
 * the configuration's attachments AND the run's forced skills, the same set
 * {@see ToolLoopService} injects, and without the group gate, because the run
 * stores it and the group gate is re-read live on every decision.
 * {@see self::resolve()} is the configuration-only answer for a caller that has
 * no run — the tool explanation in the backend, the governance simulation.
 */
final readonly class AllowedToolsResolver
{
    public function __construct(
        private SkillComposer $composer,
        private ToolRegistry $registry,
    ) {}

    /**
     * The configuration-only allow-list, group gate applied — for a caller with
     * no run. A run resolves its own list once with {@see self::resolveForRun()}.
     *
     * @return list<string>|null null = no declaring skill (all tools); a list = the declared union
     */
    public function resolve(LlmConfiguration $config, ?Task $task = null): ?array
    {
        $taskSkills = $task instanceof Task ? $this->toList($task->getSkills()) : [];

        return $this->applyGroupGate($this->declaredUnion($config, $taskSkills), $config->getAllowedToolGroupsList());
    }

    /**
     * The run's skill allow-list (ADR-038 item 5): the union over every
     * effective skill the run carries, resolved once at run start.
     *
     * `$runSkills` are the run's forced skills. They take the same slot the
     * injection path gives them ({@see SkillComposer::effectiveSkills()}'s second
     * argument), so the prose that reaches the prompt and the tools it may call
     * stay one selection. The group gate is NOT applied here: the list is stored
     * with the run, and the group gate is re-read live through
     * {@see self::applyGroupGateTo()}.
     *
     * An attached or forced process skill contributes nothing — no tools and
     * no restriction — unless the run invokes it (ADR-214 items 5 and 6).
     *
     * @param list<Skill> $runSkills
     * @param list<Skill> $invokedSkills the skills this run invokes; pinned process skills feed this on resume and continuation (ADR-214 item 10)
     */
    public function resolveForRun(LlmConfiguration $config, array $runSkills, ?Task $task = null, array $invokedSkills = []): SkillToolAllowList
    {
        $taskSkills = $task instanceof Task ? $this->toList($task->getSkills()) : [];

        return new SkillToolAllowList($this->declaredUnion($config, [...$taskSkills, ...$runSkills], $invokedSkills));
    }

    /**
     * A run's stored skill list with the configuration's live group gate on
     * top — the allow-list {@see ToolCallPolicy} enforces for that run.
     *
     * @return list<string>|null
     */
    public function applyGroupGateTo(SkillToolAllowList $list, LlmConfiguration $config): ?array
    {
        return $this->applyGroupGate($list->toolNames, $config->getAllowedToolGroupsList());
    }

    /**
     * @param list<Skill> $additionalSkills
     * @param list<Skill> $invokedSkills
     *
     * @return list<string>|null
     */
    private function declaredUnion(LlmConfiguration $config, array $additionalSkills, array $invokedSkills = []): ?array
    {
        $invoked = [];
        foreach ($invokedSkills as $skill) {
            $invoked[spl_object_id($skill)] = true;
        }

        $declared = [];
        // Every admitted record, not only the one a (source, identifier) twin
        // leaves in effect: a renamed identifier cannot drop a restriction.
        $admitted = $this->composer->admittedSkills([...$invokedSkills, ...$this->toList($config->getSkills())], $additionalSkills);
        // An invoked skill that is not admitted (disabled, below the trust
        // floor) grants nothing, and the run is not left unrestricted because
        // of that: fail closed.
        $any = array_diff_key($invoked, array_flip(array_map(spl_object_id(...), $admitted))) !== [];
        foreach ($admitted as $skill) {
            // What the skill may declare, not its live field: an unapproved
            // backend skill or one whose source no longer vouches grants
            // nothing, and a process skill the run does not invoke has no
            // opinion (ADR-214 items 3 and 5, {@see SkillComposer::declaredTools()}).
            $list = $this->composer->declaredTools($skill, isset($invoked[spl_object_id($skill)]));
            if ($list === null) {
                continue;
            }

            $any = true;
            foreach ($list as $name) {
                $declared[$name] = true;
            }
        }

        return $any ? array_keys($declared) : null;
    }

    /**
     * Intersect the skill-derived allow-list with the configuration's
     * `allowed_tool_groups` gate.
     *
     * An empty group set means "no group restriction" and passes `$names`
     * through unchanged. A non-empty set restricts to registered tools whose
     * {@see ToolInterface::getGroup()} is in the set — combined with the skill
     * list when one exists, otherwise as the allow-list itself. The global
     * group/tool enable cascade (ToolAvailabilityService) applies on top in
     * the runtime gate regardless.
     *
     * @param list<string>|null $names
     * @param list<string>      $groupSet
     *
     * @return list<string>|null
     */
    private function applyGroupGate(?array $names, array $groupSet): ?array
    {
        if ($groupSet === []) {
            return $names;
        }

        $inGroups = [];
        foreach ($this->registry->names() as $name) {
            $tool = $this->registry->get($name);
            if ($tool instanceof ToolInterface && in_array($tool->getGroup(), $groupSet, true)) {
                $inGroups[] = $name;
            }
        }

        if ($names === null) {
            return $inGroups;
        }

        return array_values(array_intersect($names, $inGroups));
    }

    /**
     * @param iterable<Skill> $skills
     *
     * @return list<Skill>
     */
    private function toList(iterable $skills): array
    {
        $list = [];
        foreach ($skills as $skill) {
            $list[] = $skill;
        }

        return $list;
    }
}
