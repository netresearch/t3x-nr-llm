<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * What one provider call actually consumed, and what that cost (ADR-174).
 *
 * The counterpart of {@see RequestComplexity}: that one estimates a request
 * before it is answered, this one reports what came back. Both hang off the
 * same telemetry row, so a shape, a decision and a price share one
 * ``correlation_id`` — which is what ``tx_nrllm_service_usage`` cannot give,
 * being a daily aggregate with no per-call key.
 *
 * **Null is a measurement that did not happen, and never a zero.** A provider
 * that reports no usage block leaves the token fields null; a model with no
 * pricing leaves the cost null. Both cases previously surfaced as ``0`` — the
 * cost of a free local model and the cost of an unpriced one were the same
 * number, which is precisely the arm a cheap-model experiment is about.
 *
 * Prompt-free like the row it is written to: two counts, a price and a model
 * name.
 *
 * @api
 */
final readonly class ProviderCallUsage
{
    /**
     * @param ?int   $inputTokens   prompt tokens as the PROVIDER reported them, or null where it
     *                              reported no usage at all. Not an estimate — {@see RequestFacts}
     *                              and {@see RequestComplexity} hold those.
     * @param ?int   $outputTokens  completion tokens as reported, null under the same condition.
     *                              A response that genuinely produced nothing still reports a
     *                              prompt count, so `0` here is a measured zero.
     * @param ?float $cost          the money the tokens above cost, derived from the serving
     *                              model's pricing. NULL where no price is known — an unpriced
     *                              or free model yields real tokens and no cost, rather than a
     *                              zero that reads as "this was free" for both cases.
     * @param string $responseModel the model id the PROVIDER named on the response. Distinct from
     *                              the configuration's model: a provider may resolve an alias to a
     *                              dated snapshot, and which one answered is the joinable fact.
     *                              '' where the response named none.
     */
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?float $cost,
        public string $responseModel,
    ) {}
}
