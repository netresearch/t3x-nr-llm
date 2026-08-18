<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

use Netresearch\NrLlm\Domain\ValueObject\ProviderCallUsage;
use Netresearch\NrLlm\Domain\ValueObject\RequestComplexity;
use Netresearch\NrLlm\Domain\ValueObject\RequestFacts;
use Netresearch\NrLlm\Domain\ValueObject\RoutingSummary;

/**
 * One immutable telemetry row, produced by TelemetryMiddleware and written by
 * TelemetryRepository (ADR-058).
 *
 * Privacy by construction: this DTO has no field for a prompt, a response, or
 * an exception message. `errorClass` is the exception FQCN only — messages can
 * carry payload fragments, so they are never captured here.
 */
final readonly class TelemetryRecord
{
    /**
     * @param string             $configurationIdentifier       the configuration the caller REQUESTED
     * @param string             $servedConfigurationIdentifier the configuration that ANSWERED. Equal to the
     *                                                          requested one unless a fallback swap served the
     *                                                          run; a run nothing served keeps the requested
     *                                                          values. The three served* fields always move
     *                                                          together — a row never mixes one configuration's
     *                                                          identifier with another's provider.
     * @param ?int               $timeToFirstTokenMs            wall-clock milliseconds from the start of
     *                                                          the run to the first streamed chunk. Only
     *                                                          the streaming lifecycle (ADR-062) supplies
     *                                                          it; every non-streaming pipeline run leaves
     *                                                          it null (there is no partial-response
     *                                                          milestone to measure), which is stored as
     *                                                          SQL NULL — distinct from a genuine 0 ms.
     * @param ?RoutingSummary    $routingSummary                why the model that served the run was chosen
     *                                                          (ADR-156). Null whenever nothing was chosen:
     *                                                          fixed mode names its own model, and the
     *                                                          service paths resolve no configuration.
     *                                                          Still prompt-free — a mode name, a count and
     *                                                          a set of reason names.
     * @param ?RequestComplexity $complexity                    how involved the request was (ADR-156).
     *                                                          Observation only; nothing routes on it. Null
     *                                                          on the paths that carry no measurable
     *                                                          payload.
     * @param ?RequestFacts      $requestFacts                  what the request was BEFORE a model was
     *                                                          chosen (ADR-174). Model-independent by
     *                                                          construction, which is what separates it
     *                                                          from the complexity record above. Null on
     *                                                          the paths that form no fact set.
     * @param ?ProviderCallUsage $callUsage                     what the provider actually reported and what
     *                                                          it cost (ADR-174). Null wherever no provider
     *                                                          call produced a token-shaped response: a
     *                                                          cache hit, a failed run, a streamed run, a
     *                                                          specialized operation.
     * @param ?int               $providerRetries               HTTP attempts an adapter had to repeat during
     *                                                          this run (ADR-174). Both write sites hold the
     *                                                          counter and always pass a count, so every row
     *                                                          this version writes carries one and a recorded
     *                                                          0 is a measured "no retry". Nullable only so
     *                                                          the parameter can be omitted — which no
     *                                                          production caller does; the column is
     *                                                          nullable because rows written before it
     *                                                          existed have no value for it.
     * @param string             $sourceExtension               which piece of software made the call
     *                                                          (ADR-177), e.g. "ai_seo_helper"; '' for an
     *                                                          unannotated call — identical to a
     *                                                          pre-feature row on purpose.
     * @param string             $sourceOperation               the operation inside the calling software
     *                                                          (ADR-177), e.g. "requestAi"; '' when the
     *                                                          caller named only itself.
     */
    public function __construct(
        public string $correlationId,
        public string $operation,
        public string $provider,
        public string $model,
        public string $configurationIdentifier,
        public int $beUser,
        public bool $success,
        public string $errorClass,
        public int $latencyMs,
        public bool $cacheHit,
        public int $fallbackAttempts,
        public string $servedConfigurationIdentifier,
        public string $servedProvider,
        public string $servedModel,
        public ?int $timeToFirstTokenMs = null,
        public ?RoutingSummary $routingSummary = null,
        public ?RequestComplexity $complexity = null,
        public ?RequestFacts $requestFacts = null,
        public ?ProviderCallUsage $callUsage = null,
        public ?int $providerRetries = null,
        public string $sourceExtension = '',
        public string $sourceOperation = '',
    ) {}
}
