<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\DroppedSource;
use Netresearch\NrLlm\Domain\ValueObject\InjectedContext;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;

/**
 * Per-run prompt augmentation for an inspectable {@see ToolLoopService} run,
 * used by the admin playground to reproduce and probe a configuration.
 *
 * - {@see self::$forcedSkills}: extra skills to inject on top of the
 *   configuration's own, so an admin can test a skill the config does not
 *   (yet) carry. Injected as additional "task" skills into the first user
 *   message alongside the configuration skills.
 * - {@see self::$forcedSnippets}: prompt snippets to add as separate leading
 *   system messages, one per snippet, before the user prompt.
 * - {@see self::$dryRun}: assemble the full message list (system prompt +
 *   snippets + skills + user) and record it WITHOUT calling the provider.
 *
 * @api
 */
final readonly class RunAugmentation
{
    /**
     * @param list<Skill>         $forcedSkills
     * @param list<PromptSnippet> $forcedSnippets
     * @param list<DroppedSource> $droppedSources sources asked for that did not arrive
     */
    public function __construct(
        public array $forcedSkills = [],
        public array $forcedSnippets = [],
        public bool $dryRun = false,
        /**
         * Forced sources this run asked for and did not get (ADR-179).
         *
         * Empty is the normal case and means "nothing was dropped", never
         * "not checked": a caller that builds the augmentation by hand has
         * nothing to compare against, and a run whose sources all resolved
         * is indistinguishable from it — deliberately, because both are the
         * same statement about what reached the model.
         *
         * Appended last with a default, so every existing caller keeps
         * working. The class is on the frozen surface, so the growth is
         * announced rather than silent.
         */
        public array $droppedSources = [],
        /**
         * The process the run invokes or continues (ADR-228): the pin whose
         * approval snapshot the loop composes as an instruction section and
         * whose declaration it adds to the run's skill allow-list.
         *
         * Set by the runtime from the run's invocation or its predecessor,
         * after its start checks; {@see \Netresearch\NrLlm\Service\Agent\AgentRunExecutor}
         * replaces whatever a caller put here, so no caller of the runtime
         * hands in a pin. Appended last with a default, like the member
         * above.
         *
         * @internal Set by the runtime only. Not part of the @api contract
         *           even though the class is: a caller that passes a pin
         *           to the loop directly skips the start checks, and a run
         *           started through {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface}
         *           never carries one it did not derive (ADR-228).
         */
        public ?SkillPin $processPin = null,
        /**
         * Whether the loop bakes the effective system prompt into the
         * transcript as its first message (ADR-060 assembly), which the
         * playground needs for its forced snippets and its inspector. False
         * for an augmentation the runtime builds only to carry a process into
         * a run whose caller sent none: that run assembles exactly as it would
         * without an augmentation, its caller's own system message included.
         *
         * @internal set by the runtime only (ADR-228)
         */
        public bool $bakesSystemPrompt = true,
        /**
         * Whether the process starts with this run, so its section passes the
         * window admission of ADR-214 item 7. False for a process the run
         * continues from its predecessor: a section the conversation already
         * holds is not admitted again, or a long tour would fail once its
         * history grew past the headroom.
         *
         * @internal set by the runtime only (ADR-228)
         */
        public bool $admitsProcess = false,
    ) {}

    /**
     * An augmentation that only carries the run's process (ADR-228), for a
     * caller that sent none: nothing forced, no dry run, no baked prompt.
     *
     * @param list<DroppedSource> $droppedSources the process pins the continuation dropped
     *
     * @internal called by the runtime only (ADR-228)
     */
    public static function forProcess(?SkillPin $pin, array $droppedSources = [], bool $admitsProcess = false): self
    {
        return new self(droppedSources: $droppedSources, processPin: $pin, bakesSystemPrompt: false, admitsProcess: $admitsProcess);
    }

    /**
     * The same augmentation with the run's process pin set, or cleared with
     * null, and the pins a continuation dropped added to its dropped sources
     * (ADR-228).
     *
     * @param list<DroppedSource> $droppedSources
     *
     * @internal called by the runtime only (ADR-228)
     */
    public function withProcess(?SkillPin $pin, array $droppedSources = [], bool $admitsProcess = false): self
    {
        return new self(
            $this->forcedSkills,
            $this->forcedSnippets,
            $this->dryRun,
            [...$this->droppedSources, ...$droppedSources],
            $pin,
            $this->bakesSystemPrompt,
            $admitsProcess,
        );
    }

    /**
     * The forced set in the shape the ADR-144 ceiling reads (ADR-164).
     *
     * The dry-run flag is deliberately not carried: a dry run assembles the
     * messages and returns without calling a provider, so nothing leaves the
     * installation and there is no send for the ceiling to judge.
     */
    public function injectedContext(): InjectedContext
    {
        return new InjectedContext($this->forcedSnippets, $this->forcedSkills);
    }
}
