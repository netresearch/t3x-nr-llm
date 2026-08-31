<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How one call turned out, per ADR-176.
 *
 * The source is derived from the case rather than stored beside it. ADR-176
 * forbids folding the two sources into one figure, and a separate column would
 * make "explicit row carrying an observed value" a state the database can hold.
 * Here it is not expressible.
 *
 * No case describes an approval. An approval can be withheld for governance,
 * policy or content reasons that say nothing about how the model did, so
 * treating a refusal as a quality signal would measure the gate instead
 * (ADR-176, ADR-172).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
enum CallOutcome: string
{
    /**
     * A backend user said the result was useful.
     */
    case HELPFUL = 'helpful';

    /**
     * A backend user said it was not.
     */
    case NOT_HELPFUL = 'not_helpful';

    /**
     * The generated text reached a record and nothing changed it inside the
     * observation window (ADR-185).
     */
    case ACCEPTED_UNCHANGED = 'accepted_unchanged';

    /**
     * It reached a record and a human changed it before releasing it.
     */
    case EDITED = 'edited';

    /**
     * It reached a record and the record was deleted.
     */
    case DISCARDED = 'discarded';

    /**
     * The derivation ran and could not decide (ADR-185).
     *
     * A value rather than an absent row, because absence already means "the
     * window has not closed yet". One storage state cannot hold both facts, and
     * the difference is the whole point: `ACCEPTED_UNCHANGED` inferred from
     * missing history would be this signal's most plausible lie.
     */
    case UNKNOWN = 'unknown';

    public function source(): CallOutcomeSource
    {
        return match ($this) {
            self::HELPFUL, self::NOT_HELPFUL => CallOutcomeSource::EXPLICIT,
            self::ACCEPTED_UNCHANGED, self::EDITED, self::DISCARDED, self::UNKNOWN => CallOutcomeSource::OBSERVED,
        };
    }

    /**
     * Whether a writer for this case exists today.
     *
     * True for every case since ADR-185 gave the observed source its deriver.
     * The method stays because the question it answers is worth being able to
     * ask in code: ADR-176 shipped the two sources apart, and a case declared
     * ahead of its writer is a state this codebase has held before.
     */
    public function isImplemented(): bool
    {
        return true;
    }
}
