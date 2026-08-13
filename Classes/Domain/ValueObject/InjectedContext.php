<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;

/**
 * The sources one run injects on top of its configuration (ADR-164).
 *
 * The ADR-144 ceiling reads these alongside the configuration's own snippets
 * and skills, so a run cannot carry, per call, context the configuration's
 * trust zone would refuse.
 *
 * A value object rather than {@see \Netresearch\NrLlm\Service\Tool\RunAugmentation}
 * itself, which is where the forced set is actually assembled: the architecture
 * rules forbid `LlmServiceManager` from depending on `Service\Tool`, and that
 * rule is right — the manager has no business knowing the tool loop exists. The
 * augmentation converts to this on the way in.
 *
 * @api
 */
final readonly class InjectedContext
{
    /**
     * @param list<PromptSnippet> $snippets
     * @param list<Skill>         $skills
     */
    public function __construct(
        public array $snippets = [],
        public array $skills = [],
    ) {}
}
