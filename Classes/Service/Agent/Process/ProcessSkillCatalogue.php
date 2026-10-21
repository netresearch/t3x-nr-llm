<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Process;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\InvocableProcessSkill;
use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;

/**
 * The process skills an actor may start on a configuration (ADR-214 item 10,
 * ADR-228): the list the slash-command menu offers.
 *
 * It lists exactly what a {@see \Netresearch\NrLlm\Domain\ValueObject\SkillInvocation}
 * would start, by the same rules: the actor may use the configuration, the
 * skill is attached to it, enabled, not orphaned and admitted by the trust
 * floor, and its current version is an approved instruction marked a process
 * — which needs its source at or above the instruction threshold. A
 * configuration that sets `require_second_approver` lists none, and neither
 * does a service account: neither could run a process to its end.
 *
 * @api
 */
final readonly class ProcessSkillCatalogue
{
    public function __construct(
        private ConfigurationResolver $configurations,
        private SkillComposer $composer,
    ) {}

    /**
     * @return list<InvocableProcessSkill>
     */
    public function forActor(LlmConfiguration $configuration, AiActorContext $actor): array
    {
        if ($actor->isServiceAccount()
            || $configuration->requiresSecondApprover()
            || !$this->configurations->actorMayUse($configuration, $actor)) {
            return [];
        }

        $invocable = [];
        // Both load modes: a process skill is attached 'on_demand', and an
        // invocation reaches either mode (ADR-214 items 4 and 6). A skill
        // attached in both is listed once; admittedSkills() dedupes by uid.
        foreach ($this->composer->admittedSkills($configuration->getAttachedSkills(), []) as $skill) {
            $version = $this->composer->approvedVersion($skill);
            if (!$version instanceof SkillApproval || !$version->process) {
                continue;
            }

            $invocable[] = new InvocableProcessSkill((int) $skill->getUid(), $skill->getIdentifier(), $version->name, $version->description);
        }

        return $invocable;
    }
}
