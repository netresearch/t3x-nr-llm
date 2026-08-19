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
     * The generated text reached a record and was released untouched.
     *
     * Not yet written by anything: the observed source needs a persisted write
     * target, which ADR-176 records as blocked on ADR-122's deferred contract.
     * The case is declared with its siblings so the source split has one home,
     * and {@see self::isImplemented()} says which cases a writer exists for.
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

    public function source(): CallOutcomeSource
    {
        return match ($this) {
            self::HELPFUL, self::NOT_HELPFUL => CallOutcomeSource::EXPLICIT,
            self::ACCEPTED_UNCHANGED, self::EDITED, self::DISCARDED => CallOutcomeSource::OBSERVED,
        };
    }

    /**
     * Whether a writer for this case exists today.
     *
     * False is not a defect. ADR-176 ships the explicit source first and the
     * observed one with its prerequisite; this method is what keeps that
     * statement checkable instead of a sentence in a record.
     */
    public function isImplemented(): bool
    {
        return $this->source() === CallOutcomeSource::EXPLICIT;
    }
}
