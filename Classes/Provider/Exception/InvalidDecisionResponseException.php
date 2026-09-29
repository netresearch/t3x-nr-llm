<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Exception;

use Throwable;

/**
 * A decision provider answered, but not with one valid answer per question
 * (ADR-211): a missing answer, a wrong type, a value out of range. Distinct
 * from a rejected request (a 4xx) and from a transport failure.
 *
 * @api
 */
final class InvalidDecisionResponseException extends ProviderResponseException
{
    public static function forQuestion(string $provider, string $key, string $reason): self
    {
        return new self(sprintf('%s answered question "%s" invalidly: %s', $provider, $key, $reason));
    }

    /**
     * The answer as a whole did not fit — a chat model's structured reply
     * that still missed the schema after the repair round-trip.
     */
    public static function forResponse(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('The model answered invalidly: %s', $reason), 0, $previous);
    }
}
