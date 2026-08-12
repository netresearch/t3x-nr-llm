<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * What a batch of editor actions is expected to cost, before anything starts
 * (ADR-162).
 *
 * Every number here is derived from something the batch already holds, never
 * from a typical-case guess:
 *
 * - `$providerRequests` is `$records` times the two sends one action needs — the
 *   send that decides the tool call, and the send after approval that turns the
 *   tool result into the answer. A FLOOR: the loop may take further rounds.
 * - `$inputTokensPerRequest` is {@see \Netresearch\NrLlm\Service\Context\TranscriptEstimator}
 *   measured over the ACTUAL messages of the first run request the batch built,
 *   plus the JSON schema of the one tool the run may call.
 * - `$maxOutputTokensPerRequest` is the configuration's own `maxTokens` — the
 *   ceiling the provider is asked to respect, not a guess at how long an answer
 *   will be. Null when the configuration sets none: a `maxTokens` of 0 means
 *   the request goes out with no `max_tokens` at all, so the output is
 *   unbounded rather than zero.
 * - `$costLow` / `$costHigh` come from the model record's own per-million
 *   prices via {@see \Netresearch\NrLlm\Domain\Model\Model::estimateCost()};
 *   low assumes no output at all, high assumes every send returns the ceiling.
 *   Both are null unless all three inputs exist — an input price, an output
 *   price, and a ceiling to apply the output price to. A model priced on one
 *   side only, or an unbounded ceiling, yields no range: an absent range is
 *   honest, and a bound of 0.00 reads as "free" when it means "unknown".
 *
 * How wrong it can be is documented in ADR-162 and summarised on the page that
 * renders it. The two directions do not cancel: the system prompt and skills the
 * loop prepends are NOT counted (under), while the output ceiling is far above a
 * typical editorial answer (over).
 */
final readonly class EditorActionCostEstimate
{
    public function __construct(
        public int $records,
        public int $providerRequests,
        public int $inputTokensPerRequest,
        public int $inputTokensTotal,
        /** Null when the configuration sets no ceiling, i.e. the output is unbounded. */
        public ?int $maxOutputTokensPerRequest,
        /** The model the configuration names; '' when it names none. */
        public string $modelId,
        public ?float $costLow = null,
        public ?float $costHigh = null,
    ) {}

    /**
     * The estimate for a batch with nothing to run.
     */
    public static function nothingToRun(): self
    {
        return new self(0, 0, 0, 0, null, '');
    }

    /**
     * Whether an output ceiling exists to show at all.
     *
     * Fluid asks this rather than the number: a ceiling is a positive integer
     * and its absence is null, both of which are falsy there, so a condition on
     * the value could not tell "unbounded" from a ceiling of zero.
     *
     * The template writes `{estimate.outputCeiling}`, NOT
     * `{estimate.hasOutputCeiling}`. Fluid resolves a path segment by trying
     * `get`, `is` and `has` in front of it and a public property last, so the
     * `has` prefix belongs to the accessor and never to the path — a path that
     * spells it out looks for `getHasOutputCeiling()` and silently yields null.
     */
    public function hasOutputCeiling(): bool
    {
        return $this->maxOutputTokensPerRequest !== null;
    }

    /**
     * Whether a money range can be shown at all.
     *
     * Fluid asks this rather than `{costLow}`: a legitimate 0.0 is falsy there,
     * so a condition on the amount itself would hide a real free-tier range.
     */
    public function isPriced(): bool
    {
        return $this->costLow !== null && $this->costHigh !== null;
    }
}
