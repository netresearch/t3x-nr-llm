<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\EditorActionCostEstimate;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOffer;

/**
 * One action, several records of one table, and what starting it would cost
 * (ADR-162).
 *
 * Lives beside the planner rather than in `Domain\ValueObject` because its
 * entries carry {@see \Netresearch\NrLlm\Service\Agent\AgentRunRequest}s: core
 * may not reach into the tool and agent packages (ADR-090).
 *
 * A plan is not a queue and not a batch run: it is the answer to "if you press
 * the button, what happens" — N ordinary
 * {@see \Netresearch\NrLlm\Service\Agent\AgentRunRequest}s, the records that are
 * NOT in the batch with the reason for each, and an estimate derived from the
 * requests themselves.
 *
 * It is built twice for one batch: once to render the confirmation page, and
 * again inside the POST that starts it. The second build is the authoritative
 * one — a plan carried across the request would be a permission carried across
 * a request.
 */
final readonly class EditorActionBatchPlan
{
    /**
     * @param EditorActionOffer|null       $offer           the declaration to put in front of a human — null when
     *                                                      this viewer is offered no such action on this table.
     *                                                      Presentation only: whether a given record may be acted
     *                                                      on is each entry's own answer, asked per record.
     * @param list<EditorActionBatchEntry> $entries         one per unique record number the request named,
     *                                                      in the order the request named them
     * @param int                          $discardedInputs how many entries of the request were not record
     *                                                      numbers at all and were dropped before planning
     */
    public function __construct(
        public string $toolName,
        public string $recordTable,
        public ?EditorActionOffer $offer,
        public array $entries,
        public int $discardedInputs,
        public EditorActionCostEstimate $estimate,
    ) {}

    /**
     * The records that are NOT in the batch, for the reporter that names them.
     *
     * There is deliberately no `getRunnable()` twin: the controller's loop and
     * the template both walk `$entries` in the order the request named them,
     * and a filtered second list nobody reads would be a declaration pretending
     * to be a contract.
     *
     * @return list<EditorActionBatchEntry>
     */
    public function getSkipped(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(EditorActionBatchEntry $entry): bool => !$entry->isRunnable(),
        ));
    }
}
