<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Netresearch\NrLlm\Service\Preset\PresetPreflightResult;

/**
 * What installing a pack would do to THIS installation, right now (ADR-163).
 *
 * The plan is what the operator confirms. It is computed read-only and states
 * three things: which records would be created, which are already there, and
 * whether the configuration's model requirements can be met at all — the last
 * one through the preset service's own preflight, so the answer cannot drift
 * from what the import would decide a moment later.
 *
 * The count questions are exposed as BOOLEANS as well as numbers on purpose: a
 * numeric `0` is falsy in Fluid, so a template asking `<f:if
 * condition="{plan.pendingCount}">` would silently take the else branch for a
 * fully installed pack — which is the case that matters most here.
 *
 * Two of the things installing changes are not records, and both are stated
 * here because the operator confirms this screen and can only confirm what it
 * shows:
 *
 * - `missingSnippetTags` — the pack's snippet tags its configuration does not
 *   select yet. Installing adds them, and without them the pack's snippets are
 *   composed into nothing.
 * - `affectedConfigurations` — OTHER existing configurations that already
 *   select one of those tags. A snippet is selected by tag, not by owner
 *   (ADR-031), so the snippets this install creates enter their prompts too.
 *
 * Both are plain arrays and the templates ask them directly: an empty array is
 * falsy in Fluid, a non-empty one truthy. Deliberately no `hasX()` companions —
 * Fluid's property access prefers `get`/`is`/`has` over the property itself, so
 * a `hasMissingSnippetTags()` would make `{plan.missingSnippetTags}` resolve to
 * a boolean and `<f:for>` over it fail at render time.
 */
final readonly class UseCasePackPlan
{
    /**
     * @param list<UseCasePackPlanItem>                     $tasks
     * @param list<UseCasePackPlanItem>                     $snippets
     * @param list<string>                                  $missingSnippetTags
     * @param list<array{identifier: string, name: string}> $affectedConfigurations
     */
    public function __construct(
        public UseCasePack $pack,
        public UseCasePackPlanItem $configuration,
        public array $tasks,
        public array $snippets,
        public PresetPreflightResult $preflight,
        public array $missingSnippetTags = [],
        public array $affectedConfigurations = [],
    ) {}

    /**
     * @return list<UseCasePackPlanItem>
     */
    public function getItems(): array
    {
        return [$this->configuration, ...$this->tasks, ...$this->snippets];
    }

    public function getPendingCount(): int
    {
        return count(array_filter(
            $this->getItems(),
            static fn(UseCasePackPlanItem $item): bool => !$item->installed,
        ));
    }

    public function getInstalledCount(): int
    {
        return count($this->getItems()) - $this->getPendingCount();
    }

    public function hasPendingItems(): bool
    {
        return $this->getPendingCount() > 0;
    }

    /**
     * Everything the install would do is already done.
     *
     * The tag link counts. A pack whose records all exist but whose
     * configuration does not select its snippet tags is NOT finished: its
     * snippets are read by nothing, and the confirm button is what repairs it.
     */
    public function isFullyInstalled(): bool
    {
        return $this->getPendingCount() === 0 && $this->missingSnippetTags === [];
    }

    /**
     * Whether the confirm button may be offered.
     *
     * A pack whose configuration record already exists is installable even when
     * no model currently satisfies the preset: the remaining work is tasks,
     * snippets and the tag link, and none of those needs a resolvable model.
     */
    public function isInstallable(): bool
    {
        return ($this->hasPendingItems() || $this->missingSnippetTags !== [])
            && ($this->configuration->installed || $this->preflight->satisfiable);
    }
}
