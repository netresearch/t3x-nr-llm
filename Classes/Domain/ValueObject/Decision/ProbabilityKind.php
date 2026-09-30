<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

/**
 * What the probabilities of a decision are (ADR-211). A threshold tuned on
 * one kind does not transfer to another.
 *
 * @api
 */
enum ProbabilityKind: string
{
    /**
     * Hard labels: no probabilities and no confidence. A chat model answering
     * through structured output — a probability it writes into its text is
     * not a measured one.
     */
    case None = 'none';

    /**
     * The model's own output distribution, not calibrated against observed
     * accuracy — a local zero-shot classifier.
     */
    case Distribution = 'distribution';

    /**
     * A distribution the vendor calibrates and documents as such (TypeSafe).
     */
    case Calibrated = 'calibrated';
}
