<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionCostEstimate;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOffer;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * One editorial action over several records of one table, planned as N ORDINARY
 * runs (ADR-162).
 *
 * This class starts nothing and decides nothing about authorisation. It asks
 * {@see EditorActionCatalogueInterface::runRequestFor()} once PER RECORD and
 * collects the answers. That is the whole design: N runs are N turns, so budget,
 * audit, routing, approval, tool policy and the write fence keep answering
 * exactly as they do for the single-record path, and a record the viewer may not
 * act on comes back as null and is recorded as skipped rather than dropped.
 *
 * There is deliberately no bulk runtime, no shared approval and no queue of its
 * own. A batch is a loop over a seam that already exists.
 *
 * **The catalogue's answer does not currently vary by record uid.** It
 * authorises on tool, table, configuration and viewer, and validates the uid
 * only for being a positive integer — so asking N times returns N identical
 * answers today. It is still asked N times: the catalogue is the seam that owns
 * that question, and a per-record axis added there (a record-level permission, a
 * workspace state) must reach this surface without anyone remembering to come
 * back here.
 */
final readonly class EditorActionBatchPlanner
{
    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    /**
     * The largest batch this surface accepts.
     *
     * Two real constraints set it, not taste. The batch executes its runs
     * SYNCHRONOUSLY inside one backend request — the same {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::run()}
     * the single-record path uses — and each run costs at least one provider
     * round trip; a batch that outlives `max_execution_time` is truncated
     * mid-loop, which is the one failure mode that leaves an operator unable to
     * say what started. And each run that suspends becomes its own inbox card
     * with its own verdict, so the number is also a bound on how many decisions
     * one editor hands an approver in one press.
     *
     * Twenty is the judgement inside those two bounds. Raising it means moving
     * the loop off the request onto {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::enqueue()},
     * which is a different decision than this one.
     */
    public const MAX_RECORDS = 20;

    public const REASON_NOT_OFFERED = self::LL . 'editorActions.batch.skip.notOffered';

    public const REASON_OVER_CAP = self::LL . 'editorActions.batch.skip.overCap';

    public const REASON_DUPLICATE = self::LL . 'editorActions.batch.skip.duplicate';

    /**
     * The provider calls one editor action needs to reach a written record.
     *
     * A floor, and derived rather than assumed: the first send is the one that
     * decides the tool call, at which point the declared write suspends for
     * approval (ADR-134); the second send is the one after approval that turns
     * the tool result into the run's answer. A model that re-tries, asks for a
     * second tool call, or is stopped by a guardrail lands above or below this,
     * which is why the surface says "at least".
     */
    private const PROVIDER_CALLS_PER_RUN = 2;

    public function __construct(
        private EditorActionCatalogueInterface $catalogue,
        private ToolRegistry $registry,
        private TranscriptEstimator $estimator,
    ) {}

    /**
     * Plan one action over the records `$recordUidList` names.
     *
     * `$recordUidList` is the raw request value: record numbers separated by
     * commas or whitespace. Anything that is not a positive integer is counted
     * into {@see EditorActionBatchPlan::$discardedInputs} — reported, never
     * silently absorbed.
     */
    public function plan(
        string $toolName,
        string $recordTable,
        string $recordUidList,
        string $instruction,
        AiActorContext $actor,
        ?BackendUserAuthentication $user,
    ): EditorActionBatchPlan {
        [$tokens, $discarded] = $this->parse($recordUidList);

        $entries = [];
        $seen    = [];
        $planned = 0;

        foreach ($tokens as $recordUid) {
            if (isset($seen[$recordUid])) {
                $entries[] = new EditorActionBatchEntry($recordUid, null, self::REASON_DUPLICATE);
                continue;
            }

            $seen[$recordUid] = true;

            if ($planned >= self::MAX_RECORDS) {
                $entries[] = new EditorActionBatchEntry($recordUid, null, self::REASON_OVER_CAP);
                continue;
            }

            ++$planned;

            // The catalogue, asked again for THIS record. Never once for the
            // batch: authorising the first record and inferring the rest is the
            // shortcut that turns a per-record gate into a per-table one.
            $request = $this->catalogue->runRequestFor(
                $toolName,
                $recordTable,
                $recordUid,
                $instruction,
                $actor,
                $user,
            );

            $entries[] = $request instanceof AgentRunRequest
                ? new EditorActionBatchEntry($recordUid, $request)
                : new EditorActionBatchEntry($recordUid, null, self::REASON_NOT_OFFERED);
        }

        return new EditorActionBatchPlan(
            $toolName,
            $recordTable,
            $this->offer($toolName, $recordTable, $user),
            $entries,
            $discarded,
            $this->estimate($entries, $toolName),
        );
    }

    /**
     * The declaration the page puts in front of a human — a translatable name
     * and sentence rather than the wire name (ADR-152).
     *
     * Presentation only. It is asked ONCE for the batch on purpose: it decides
     * what the heading says, never what may run. Authorisation stays where the
     * loop above put it, at one {@see EditorActionCatalogueInterface::runRequestFor()}
     * per record.
     */
    private function offer(string $toolName, string $recordTable, ?BackendUserAuthentication $user): ?EditorActionOffer
    {
        foreach ($this->catalogue->groupsFor($user, $recordTable) as $group) {
            foreach ($group->offers as $offer) {
                if ($offer->toolName === $toolName) {
                    return $offer;
                }
            }
        }

        return null;
    }

    /**
     * The record numbers a raw request value names, and how many of its entries
     * were not record numbers.
     *
     * @return array{0: list<int>, 1: int}
     */
    private function parse(string $recordUidList): array
    {
        $tokens = preg_split('/[\s,]+/', trim($recordUidList), -1, PREG_SPLIT_NO_EMPTY);

        $uids      = [];
        $discarded = 0;
        foreach ($tokens === false ? [] : $tokens as $token) {
            if (preg_match('/^\d+$/', $token) === 1 && (int)$token > 0) {
                $uids[] = (int)$token;
                continue;
            }

            ++$discarded;
        }

        return [$uids, $discarded];
    }

    /**
     * What the runnable part of this plan is expected to cost.
     *
     * Measured on the requests the plan actually holds — the first one stands
     * for all of them, because every request in a batch carries the same
     * configuration, the same tool and a prompt that differs only in a record
     * number. See {@see EditorActionCostEstimate} for what each number is and
     * ADR-162 for how wrong it can be.
     *
     * @param list<EditorActionBatchEntry> $entries
     */
    private function estimate(array $entries, string $toolName): EditorActionCostEstimate
    {
        $first   = null;
        $records = 0;
        foreach ($entries as $entry) {
            if ($entry->request instanceof AgentRunRequest) {
                $first ??= $entry->request;
                ++$records;
            }
        }

        if (!$first instanceof AgentRunRequest) {
            return EditorActionCostEstimate::nothingToRun();
        }

        // The same schema block the loop puts on the wire for this run: the
        // registry filtered by the run's own one-tool allow-list.
        $specs = array_map(
            static fn(ToolSpec $spec): array => $spec->toArray(),
            $this->registry->specs([$toolName]),
        );

        // Calibration 1.0: the runtime's learned factor belongs to a live
        // context window, and borrowing it here would make the same batch quote
        // different numbers on different days for no reason an editor can see.
        $perRequest       = $this->estimator->estimate($first->messages, $specs, 1.0);
        $providerRequests = $records * self::PROVIDER_CALLS_PER_RUN;
        $inputTotal       = $perRequest * $providerRequests;

        $configuration = $first->configuration;
        $model         = $configuration->getLlmModel();

        // A `maxTokens` of 0 is UNBOUNDED on this model, not "no output":
        // LlmConfiguration sends no `max_tokens` at all then. Reported as an
        // absent ceiling, because printing "0" would understate it by
        // everything.
        $configuredCeiling = $configuration->getMaxTokens();
        $maxOutput         = $configuredCeiling > 0 ? $configuredCeiling : null;

        // A range needs all three: both per-million rates, and a ceiling to
        // bound the upper end against. `hasPricing()` is deliberately NOT the
        // gate — it answers true when EITHER rate is set, and estimateCost()
        // charges the missing one as zero, so a model priced on one side only
        // would quote a bound of exactly 0.00. That is the "reads as free, means
        // unknown" this estimate refuses, just moved into one end of the range.
        $costLow  = null;
        $costHigh = null;
        if ($maxOutput !== null && $model instanceof Model && $model->getCostInput() > 0 && $model->getCostOutput() > 0) {
            $costLow  = $model->estimateCost($inputTotal, 0);
            $costHigh = $model->estimateCost($inputTotal, $maxOutput * $providerRequests);
        }

        return new EditorActionCostEstimate(
            $records,
            $providerRequests,
            $perRequest,
            $inputTotal,
            $maxOutput,
            $configuration->getModelId(),
            $costLow,
            $costHigh,
        );
    }
}
