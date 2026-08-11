<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\EditorAction;

/**
 * Opt-in: a {@see ToolInterface} that is also an EDITOR ACTION — a named,
 * narrowly-bounded editorial write a human can be offered by name (ADR-152).
 *
 * There is deliberately no `execute()` beside {@see ToolInterface::execute()}.
 * An editor action is declared METADATA on top of the tool contract, not a
 * second kind of thing: two executors would mean two write paths, two fences
 * and two audit stories, and the second one would be the one nobody hardened.
 * What this interface adds is everything the tool contract cannot express
 * because it was written for a language model — a translatable name, a
 * sentence for a human, an icon, and the record types the action addresses.
 *
 * A marker-style optional interface like {@see ToolEffectInterface} and
 * {@see ToolPreviewInterface}: the read-only builtins are untouched, and a tool
 * that does not implement it is simply not offered as an editor action.
 *
 * Implementing it does NOT make a tool a write, and not implementing it does
 * not make a write safe. The runtime's write axis is
 * {@see \Netresearch\NrLlm\Domain\Enum\ToolEffect} (ADR-111/134); this
 * declaration is read by catalogues and UIs only.
 */
interface EditorActionInterface
{
    /**
     * The human-facing declaration of this action.
     *
     * Constant data: the only caller is
     * {@see ToolAvailabilityServiceInterface::editorActions()}, which a backend
     * module calls while it renders — never the tool-call gate — so this must
     * not query, authorise or otherwise depend on the acting user. What a
     * specific CALL would do to a specific record is
     * {@see ToolPreviewInterface::previewCall()}'s job, and that one runs in the
     * run's actor context for exactly that reason.
     *
     * A declaration that throws costs its row the icon and the translated name
     * and nothing else: the tool then renders under its wire name, and both the
     * enable state and the run are unaffected.
     */
    public function getEditorAction(): EditorAction;
}
