<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;

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
 */
final readonly class RunAugmentation
{
    /**
     * @param list<Skill>         $forcedSkills
     * @param list<PromptSnippet> $forcedSnippets
     */
    public function __construct(
        public array $forcedSkills = [],
        public array $forcedSnippets = [],
        public bool $dryRun = false,
    ) {}
}
