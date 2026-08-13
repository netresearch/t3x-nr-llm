<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Netresearch\NrLlm\Domain\ValueObject\EditorAction;

/**
 * One editor action a pack was designed for, as this installation currently
 * answers for it (ADR-168).
 *
 * Three separate facts, kept separate because an operator acts on each one
 * differently:
 *
 * - `declared` — this installation has no registered tool of that name that
 *   declares an editor action. Either the pack names something a third-party
 *   extension provides and it is not installed, or the pack has a typo. Both
 *   need to be visible; a missing declaration silently rendering as "disabled"
 *   would send the operator to a Tools module that has no such row.
 * - `enabled` — the admin toggle in the Tools module, group AND tool cascade.
 *   Meaningful only when `declared` is true.
 * - `group` — the tool group the action's switch sits under in the Tools
 *   module, which is the half that says WHERE to go. Empty when `declared` is
 *   false, including for a registered tool that is no editor action: its group
 *   is real and would not produce the action.
 * - `declaration` — the human-facing {@see EditorAction}: label, description,
 *   icon and the record types the action addresses. Null when undeclared.
 *
 * `enabled` is the ADMIN state, deliberately not "would the person looking at
 * this screen be offered the action right now". The per-viewer answer is
 * {@see \Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface::groupsFor()},
 * and it folds four different reasons — no default configuration, no access to
 * it, the tool gate, the record type — into one absent row. On a plan screen
 * that would make a typo indistinguishable from a missing default
 * configuration. The plan answers what an admin can act on; the catalogue
 * answers what an editor is offered.
 */
final readonly class PackEditorActionState
{
    public function __construct(
        public string $toolName,
        public bool $declared,
        public bool $enabled,
        public string $group = '',
        public ?EditorAction $declaration = null,
    ) {}

    /**
     * The tables whose records this action addresses, or an empty list when the
     * action is not declared here.
     *
     * @return list<string>
     */
    public function getRecordTypes(): array
    {
        // `?->` would be redundant: the null-coalescing property fetch already
        // tolerates a null left side.
        return $this->declaration->recordTypes ?? [];
    }
}
