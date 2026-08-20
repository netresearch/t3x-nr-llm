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
    ) {}

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
