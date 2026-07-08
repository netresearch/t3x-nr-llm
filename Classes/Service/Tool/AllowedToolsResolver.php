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
use Netresearch\NrLlm\Service\Skill\SkillComposer;

/**
 * Resolves the effective allowed-tools allow-list for a run.
 *
 * Semantics (fail-closed on declaration): the allow-list is the UNION of the
 * declared lists of every effective skill (config + task, enabled, non-orphaned,
 * deduped — exactly the set SkillComposer composes into the prompt). A skill that
 * declares no `allowed-tools` key (its accessor returns null) contributes no
 * opinion. When NO effective skill declares anything, this returns null meaning
 * "no skill-imposed restriction" (all registry tools are permitted). When at least
 * one skill declares, the union is returned — and a lone declared empty list yields
 * `[]`, i.e. no tools at all.
 */
final readonly class AllowedToolsResolver
{
    public function __construct(
        private SkillComposer $composer,
        private ToolRegistry $registry,
    ) {}

    /**
     * @return list<string>|null null = no declaring skill (all tools); a list = the declared union
     */
    public function resolve(LlmConfiguration $config, ?Task $task = null): ?array
    {
        $configSkills = $this->toList($config->getSkills());
        $taskSkills   = $task !== null ? $this->toList($task->getSkills()) : [];

        $declared = [];
        $any      = false;
        foreach ($this->composer->effectiveSkills($configSkills, $taskSkills) as $skill) {
            $list = $skill->getAllowedToolsList();
            if ($list === null) {
                continue;
            }
            $any = true;
            foreach ($list as $name) {
                $declared[$name] = true;
            }
        }

        $names = $any ? array_keys($declared) : null;

        return $this->applyGroupGate($names, $config->getAllowedToolGroupsList());
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
            if ($tool !== null && in_array($tool->getGroup(), $groupSet, true)) {
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
