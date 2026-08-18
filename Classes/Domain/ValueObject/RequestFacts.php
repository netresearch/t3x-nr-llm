<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * What the request is, measured BEFORE a model is chosen (ADR-174).
 *
 * {@see RequestComplexity} is measured after the model is chosen and partly
 * FROM it — its size term is estimated tokens against the budget of the model
 * that was already selected, so it can describe a decision but never inform
 * one. This fact set is model-independent by construction and is formed before
 * the pipeline runs, which is before any resolution happens.
 *
 * Explicitly NOT here, and deliberately: context utilisation, the chosen model,
 * its window, its price. Every one of those is a property of a decision that
 * has not been taken yet, and admitting one would recreate the circularity this
 * exists to break.
 *
 * The operation is not repeated either — ``tx_nrllm_telemetry.operation``
 * already names it on the same row.
 *
 * WHAT IS COUNTED is the transcript the caller handed over, which is not quite
 * what goes on the wire: the configuration's stored system prompt is prepended
 * later, by
 * {@see \Netresearch\NrLlm\Service\MessageShaper::applySystemPrompt()}, and is
 * in none of these figures. That is a consequence of the measurement point, not
 * an oversight — the effective prompt comes from the call options, which are
 * built from the resolved model, so counting it would mean measuring after the
 * decision this record exists to precede. A system message the CALLER supplied
 * is counted (as a message and as bytes, never as a turn), and
 * {@see RequestComplexity} draws the same line, so the two records on one row
 * stay comparable.
 *
 * OBSERVATION ONLY. Nothing routes on this; :ref:`ADR-156 <adr-156>` keeps its
 * observer-only status and names what must be true before anything may.
 *
 * Prompt-free: four counts, a size and a shape.
 *
 * @api
 */
final readonly class RequestFacts
{
    /**
     * @param int    $messageCount  messages the caller handed over, a system message among them
     *                              if the caller supplied one — see the note above on the
     *                              configuration's system prompt, which is not one of them
     * @param int    $turnCount     conversation turns — the message count minus system messages.
     *                              The same rule {@see RequestComplexity} counts by, so the two
     *                              records stay comparable on the one row they share.
     * @param int    $toolCount     tool schemas the send carries
     * @param int    $payloadBytes  total byte length of those message contents. BYTES, like
     *                              {@see RequestComplexity::$payloadBytes} and for the same reason
     *                              (:ref:`ADR-121 <adr-121>`): bytes per token stay roughly
     *                              comparable across scripts, characters do not.
     * @param int    $tokenEstimate a provider- and model-independent token estimate for this
     *                              payload, from the same estimator the context fit uses but with
     *                              no calibration and no budget — so it can be compared against the
     *                              post-fit figure without being derived from a model.
     * @param string $shape         the {@see \Netresearch\NrLlm\Domain\Enum\RequestShape} value
     */
    public function __construct(
        public int $messageCount,
        public int $turnCount,
        public int $toolCount,
        public int $payloadBytes,
        public int $tokenEstimate,
        public string $shape,
    ) {}
}
