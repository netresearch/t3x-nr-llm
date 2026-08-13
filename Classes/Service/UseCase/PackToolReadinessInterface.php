<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

/**
 * What this installation answers about the tool-module things a pack RECOMMENDS
 * (ADR-168).
 *
 * The seam exists because of ADR-090, not because a second implementation is
 * expected. `Service\UseCase` is core; the tool registry, the admin toggles and
 * the editor-action declarations are `nr_llm_tools`, and `ModuleSeamTest`
 * forbids core importing that namespace — a core class asking
 * {@see \Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface}
 * directly would make the two candidate packages mutually dependent. So core
 * declares the question and the tool module answers it
 * ({@see \Netresearch\NrLlm\Service\Tool\PackToolReadiness}).
 *
 * Both methods are READ-ONLY and advisory. Nothing here enables a group, enables
 * a tool or starts a run — a pack states what it was built for, and acting on
 * that stays the administrator's decision in the Tools module (ADR-145,
 * ADR-140).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface PackToolReadinessInterface
{
    /**
     * The live state of each named editor action, in the order asked, one entry
     * per name — including the names this installation does not know, because a
     * silently dropped row is exactly the typo the plan exists to show.
     *
     * @param list<string> $toolNames
     *
     * @return list<PackEditorActionState>
     */
    public function editorActionStates(array $toolNames): array;

    /**
     * The live state of each named tool group, in the order asked, one entry per
     * name — same contract, same reason.
     *
     * @param list<string> $groups
     *
     * @return list<PackToolGroupState>
     */
    public function toolGroupStates(array $groups): array;
}
