<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Complexity;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Domain\ValueObject\RequestComplexity;

/**
 * Measures how involved a request is. Decides nothing (ADR-156).
 *
 * :ref:`ADR-142 <adr-142>` refused to route on complexity because no evidence
 * existed that a complexity score predicts anything. This produces that
 * evidence and nothing else: it has exactly one caller chain — the telemetry
 * scratchpad — and no path into {@see \Netresearch\NrLlm\Service\Routing\CandidateRanker},
 * {@see \Netresearch\NrLlm\Service\Routing\EligibilityEvaluator} or
 * {@see \Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode}. Grep for a consumer
 * before adding one; ADR-156 names the three things that must hold first.
 *
 * @internal
 */
final readonly class RequestComplexityEstimator
{
    /** Ceiling of the turn-count term, reached at {@see self::TURN_CAP} turns. */
    private const TURN_TERM_MAX = 40;

    private const TURN_CAP = 8;

    /** Ceiling of the tool term, reached at {@see self::TOOL_CAP} tools. */
    private const TOOL_TERM_MAX = 30;

    private const TOOL_CAP = 5;

    /** Ceiling of the size term, reached when the send fills its budget. */
    private const SIZE_TERM_MAX = 30;

    /**
     * The transcript reader is shared with {@see RequestFactsCollector} so that
     * "a turn", "tool traffic" and the byte count mean the same thing in both
     * records — the two sit on one row precisely to be compared (ADR-174).
     *
     * Defaulted rather than injected: this class is constructed inline by
     * {@see \Netresearch\NrLlm\Service\LlmServiceManager}, whose constructor is
     * pinned by the shared test factory.
     */
    public function __construct(
        private MessageInspector $inspector = new MessageInspector(),
    ) {}

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages  the bounded transcript, post-pruning — NOT the
     *                                                          bytes on the wire: the configuration's system
     *                                                          prompt is prepended after the fit, so it is in
     *                                                          neither this list nor the byte count. The same
     *                                                          line {@see RequestFactsCollector} draws, which
     *                                                          is what keeps the two records on one row
     *                                                          comparable (ADR-174)
     * @param int                                    $toolCount tool schemas this send carries
     * @param ?ContextFitResult                      $fit       the context fit for this send (ADR-107/143),
     *                                                          or null where none ran — the token and
     *                                                          utilisation figures come from it, and stay
     *                                                          null rather than guessed when it is absent
     */
    public function estimate(array $messages, int $toolCount, ?ContextFitResult $fit): RequestComplexity
    {
        $toolCount = max(0, $toolCount);

        $bytes        = $this->inspector->payloadBytes($messages);
        $conversation = $this->inspector->turnCount($messages);
        $carriesTools = $toolCount > 0 || $this->inspector->carriesToolTraffic($messages);

        $percent = $this->contextPercent($fit);

        return new RequestComplexity(
            score: $this->score($conversation, $toolCount, $percent),
            payloadBytes: $bytes,
            tokenEstimate: $fit?->estimatedTokens,
            toolCount: $toolCount,
            contextPercent: $percent,
            shape: $this->inspector->shape($conversation, $carriesTools)->value,
        );
    }

    /**
     * Estimated tokens as a percentage of the budget, or null when no fit ran.
     *
     * A non-positive budget also yields null: it means the window is unknown
     * for this model, and dividing by it would report a utilisation the fit
     * never claimed.
     */
    private function contextPercent(?ContextFitResult $fit): ?int
    {
        if (!$fit instanceof ContextFitResult || $fit->budget <= 0) {
            return null;
        }

        return (int)round($fit->estimatedTokens * 100 / $fit->budget);
    }

    /**
     * Three terms, each capped, summing to at most 100.
     *
     * The weights are judgement, not calibration — the same admission
     * :ref:`ADR-142 <adr-142>` makes about the ranking weights. They are chosen
     * so that each term can matter on its own (a long conversation, a large
     * tool set, and a send that fills its window are each independently a
     * reason to call a request involved) and so that no term can carry the
     * score alone. Nothing depends on the exact numbers, because nothing routes
     * on the result; if the evidence ever says a different shape predicts
     * better, changing them breaks no behaviour.
     *
     * An unmeasured size contributes zero rather than a neutral midpoint. The
     * midpoint rule belongs to ranking, where an absent signal must not demote
     * a candidate against its rivals; here there are no rivals, and inventing
     * half a term for a send nobody measured would put a number in the column
     * that no measurement produced.
     */
    private function score(int $conversationTurns, int $toolCount, ?int $contextPercent): int
    {
        $turnTerm = (int)round(self::TURN_TERM_MAX * min($conversationTurns, self::TURN_CAP) / self::TURN_CAP);
        $toolTerm = (int)round(self::TOOL_TERM_MAX * min($toolCount, self::TOOL_CAP) / self::TOOL_CAP);
        $sizeTerm = (int)round(self::SIZE_TERM_MAX * min($contextPercent ?? 0, 100) / 100);

        return $turnTerm + $toolTerm + $sizeTerm;
    }
}
