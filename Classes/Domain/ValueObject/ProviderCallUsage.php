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
 * Null means no available measurement, never a measured zero. A provider
 * that reports no usage block leaves the token fields null. Cost comes from
 * the response first, including a reported 0.0; otherwise it is derived from
 * known model pricing. Without either source it stays null. Two zero model
 * price fields alone do not establish that a call was measured as free.
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
     *                              A response that produced nothing can still report a prompt
     *                              count, so 0 here is a measured zero.
     * @param ?float $cost          provider-reported cost, including 0.0, or cost derived from
     *                              known serving-model pricing when the response has none.
     *                              Null when neither source supplies a cost; two zero model
     *                              price fields alone do not establish a measured free call.
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
