<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * How big and how involved one request was (ADR-156).
 *
 * OBSERVATION ONLY. Nothing routes on this. :ref:`ADR-142 <adr-142>` declined
 * complexity routing for want of evidence that a complexity score predicts
 * anything; this is the measurement that would produce that evidence, and it is
 * wired to exactly one place — a telemetry column — with no reader inside the
 * decision path. The activation criteria are written into ADR-156 rather than
 * left to whoever finds the column.
 *
 * Prompt-free by construction, like the row it is written to: a size, a count
 * and a shape, never a fragment of what was sent.
 *
 * Only scalars — see {@see RoutingSummary} for why.
 *
 * @api
 */
final readonly class RequestComplexity
{
    /**
     * @param int    $score          0-100 structural estimate. Judgement, not calibration —
     *                               see {@see \Netresearch\NrLlm\Service\Complexity\RequestComplexityEstimator}
     *                               for the three terms and ADR-156 for what has to be true
     *                               before anything is allowed to route on it.
     * @param int    $payloadBytes   total byte length of the message contents on the wire. BYTES,
     *                               not characters, and named so: the token estimator this shares a
     *                               call with counts bytes too (ADR-121), and a second unit here
     *                               would make the two numbers incomparable.
     * @param ?int   $tokenEstimate  the context estimator's token figure for this send, or null
     *                               where no fit ran (no context-window manager wired). Null is not
     *                               zero: an unmeasured send is not an empty one.
     * @param int    $toolCount      tool schemas on the wire for this send
     * @param ?int   $contextPercent estimated tokens as a percentage of the model's budget, or null
     *                               where no fit ran. May exceed 100 — that is the overflow case
     *                               ADR-143 reports, and clamping it would hide it.
     * @param string $shape          the {@see \Netresearch\NrLlm\Domain\Enum\RequestShape} value
     */
    public function __construct(
        public int $score,
        public int $payloadBytes,
        public ?int $tokenEstimate,
        public int $toolCount,
        public ?int $contextPercent,
        public string $shape,
    ) {}
}
