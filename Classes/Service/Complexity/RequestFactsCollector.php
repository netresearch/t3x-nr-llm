<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Complexity;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RequestFacts;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;

/**
 * Describes a request before a model exists to describe it against (ADR-174).
 *
 * The one property that matters here is what this class CANNOT see: no
 * configuration, no {@see \Netresearch\NrLlm\Domain\Model\Model}, no context
 * budget, no price. {@see RequestComplexityEstimator} takes a
 * {@see \Netresearch\NrLlm\Domain\ValueObject\ContextFitResult} and is therefore
 * downstream of a selection; this takes messages and tool schemas and nothing
 * else, which is what lets its caller run it before the pipeline does anything.
 *
 * Decides nothing. Its only consumer is a telemetry column group, exactly as
 * :ref:`ADR-156 <adr-156>` requires of the complexity estimator, and ADR-174
 * repeats that requirement rather than relaxing it. Grep for a consumer in
 * {@see \Netresearch\NrLlm\Service\Routing\CandidateRanker} or
 * {@see \Netresearch\NrLlm\Service\Routing\EligibilityEvaluator} before adding
 * one.
 *
 * @internal
 */
final readonly class RequestFactsCollector
{
    /**
     * @param MessageInspector    $inspector shared with {@see RequestComplexityEstimator} so both
     *                                       records count a turn the same way
     * @param TranscriptEstimator $estimator the same estimator the context fit uses
     *                                       (:ref:`ADR-107 <adr-107>`), called with a calibration of
     *                                       1.0 — the calibration is grown from a model's real
     *                                       prompt-token counts, and a model is exactly what this
     *                                       measurement is not allowed to know
     */
    public function __construct(
        private MessageInspector $inspector = new MessageInspector(),
        private TranscriptEstimator $estimator = new TranscriptEstimator(),
    ) {}

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages  the send as the caller handed it over
     * @param list<array<string, mixed>>             $toolSpecs the tool schemas this send carries, empty for a plain chat
     */
    public function collect(array $messages, array $toolSpecs): RequestFacts
    {
        $turns = $this->inspector->turnCount($messages);

        return new RequestFacts(
            messageCount: count($messages),
            turnCount: $turns,
            toolCount: count($toolSpecs),
            payloadBytes: $this->inspector->payloadBytes($messages),
            tokenEstimate: $this->estimator->estimate($messages, $toolSpecs, 1.0),
            shape: $this->inspector->shape(
                $turns,
                $toolSpecs !== [] || $this->inspector->carriesToolTraffic($messages),
            )->value,
        );
    }
}
