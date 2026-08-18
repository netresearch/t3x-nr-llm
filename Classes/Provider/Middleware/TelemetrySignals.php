<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ProviderCallUsage;
use Netresearch\NrLlm\Domain\ValueObject\RequestComplexity;
use Netresearch\NrLlm\Domain\ValueObject\RequestFacts;
use Netresearch\NrLlm\Domain\ValueObject\RoutingSummary;

/**
 * Mutable scratchpad an inner middleware uses to signal an outer one within a
 * single pipeline run.
 *
 * The pipeline threads ONE immutable ProviderCallContext through every layer
 * and the `$next` callable only forwards the LlmConfiguration -- never a
 * context. An inner middleware therefore cannot hand a modified context back
 * out to a middleware that already captured the original. The one channel that
 * survives the unwind is a mutable object reachable from the shared context:
 * this class. ProviderCallContext carries exactly one instance (default-
 * constructed per call, see ProviderCallContext), so CacheMiddleware and
 * FallbackMiddleware can annotate it on the way in and TelemetryMiddleware --
 * the outermost layer -- reads the result on the way out.
 *
 * Deliberately NOT readonly: recording a signal is the whole point. It holds
 * only cross-cutting observability state, never payload, so it does not
 * weaken the "context carries no payload" rule of ADR-026.
 *
 * A fresh, un-annotated instance reads as "nothing happened" (no cache hit,
 * zero fallback attempts, no configuration swap), which is the correct default
 * for any pipeline run that never touches those layers.
 *
 * @api
 */
final class TelemetrySignals
{
    public bool $cacheHit = false;

    public int $fallbackAttempts = 0;

    /**
     * Identifier of the configuration that ANSWERED, when that is not the one
     * the caller requested. null while nothing has swapped — which the
     * TelemetryMiddleware reads as "the requested configuration served it".
     */
    public ?string $servedConfigurationIdentifier = null;

    /** Provider of the configuration that answered; null with the identifier. */
    public ?string $servedProvider = null;

    /** Model of the configuration that answered; null with the identifier. */
    public ?string $servedModel = null;

    /**
     * Why the model that ran was the one that ran (ADR-156).
     *
     * Null until a criteria-mode resolution records one, and null for good on
     * every other path: fixed mode chooses nothing, and a call with no
     * configuration resolves nothing. That is the same distinction
     * {@see \Netresearch\NrLlm\Domain\ValueObject\RoutingReadout} keeps for the
     * live readout — a decision that was never taken is not a decision with
     * empty fields.
     */
    public ?RoutingSummary $routingSummary = null;

    /**
     * How involved the request was (ADR-156). Observation only — nothing in the
     * routing path reads it, and ADR-156 states what must be true before
     * anything may.
     *
     * Null wherever nothing measures: the measurement hangs off the context fit
     * in {@see \Netresearch\NrLlm\Service\LlmServiceManager}, so only the
     * configuration-driven chat, completion, tool and stream sends record one.
     * The provider-pinned entry points do not — `chatWithTools()`, `vision()`,
     * and `chat()`/`complete()` where no default configuration resolves — nor do
     * embeddings by identifier or the specialized image/speech services. The
     * first group carries a measurable payload and is simply not on a path that
     * fits it to a window.
     */
    public ?RequestComplexity $complexity = null;

    /**
     * What the request WAS, before anything chose a model for it (ADR-174).
     *
     * Recorded by {@see \Netresearch\NrLlm\Service\LlmServiceManager} on the way
     * IN — before the pipeline runs, therefore before any resolution — which is
     * the whole point: {@see RequestComplexity} is measured after the model is
     * chosen and partly from it, so it cannot describe a request independently
     * of the answer it got.
     *
     * Only the four configuration-driven sends form a fact set — chat,
     * completion, tools and stream — so this stays null everywhere else: the
     * provider-pinned entry points, embeddings, and every specialized service
     * (image, speech and translation alike, all of which run their own
     * operations through the pipeline and so reach the telemetry row).
     */
    public ?RequestFacts $requestFacts = null;

    /**
     * What the provider reported and what it cost (ADR-174).
     *
     * Recorded by {@see UsageMiddleware} from the response that came back, so
     * it is null wherever no provider call happened or nothing token-shaped
     * came out of it: a cache hit (CacheMiddleware short-circuits above Usage),
     * a failed run, and the specialized operations that record through a tagged
     * extractor instead.
     */
    public ?ProviderCallUsage $callUsage = null;

    /**
     * CacheMiddleware calls this when it serves a stored response instead of
     * invoking the terminal.
     */
    public function recordCacheHit(): void
    {
        $this->cacheHit = true;
    }

    /**
     * FallbackMiddleware calls this with the sibling configuration whose call
     * RETURNED — never with one that was merely attempted.
     *
     * The distinction is the whole point of the signal: `fallbackAttempts`
     * already says how many siblings were dispatched, and the last of those is
     * usually the one that failed. Only a configuration that produced a
     * response is recorded as having served the run, so an exhausted chain
     * leaves this null and the row keeps naming the requested configuration.
     */
    public function recordServedBy(LlmConfiguration $configuration): void
    {
        $this->servedConfigurationIdentifier = $configuration->getIdentifier();
        $this->servedProvider                = $configuration->getProviderType();
        $this->servedModel                   = $configuration->getModelId();
    }

    /**
     * FallbackMiddleware calls this once per fallback configuration it actually
     * dispatches (the primary attempt is not counted).
     */
    public function recordFallbackAttempt(): void
    {
        ++$this->fallbackAttempts;
    }

    /**
     * {@see \Netresearch\NrLlm\Service\ConfigurationCallPlanner} calls this with
     * the summary of the automatic selection it just performed, or with null on
     * a fixed-mode configuration.
     *
     * A null does NOT clear a summary already recorded. A fallback swap
     * re-resolves against a sibling configuration on the same context, and a
     * fixed-mode sibling answering for a criteria-mode primary must not erase
     * the decision that chose the primary — the row would then claim no
     * decision was taken on a call that was routed.
     */
    public function recordRoutingSummary(?RoutingSummary $summary): void
    {
        if (!$summary instanceof RoutingSummary) {
            return;
        }

        $this->routingSummary = $summary;
    }

    /**
     * {@see \Netresearch\NrLlm\Service\LlmServiceManager} calls this once per
     * send, with what the payload measured.
     *
     * Last writer wins, unlike the routing summary: a fallback re-send measures
     * the SAME payload against the sibling's model, so the later figure is the
     * one that describes what actually went on the wire.
     */
    public function recordComplexity(RequestComplexity $complexity): void
    {
        $this->complexity = $complexity;
    }

    /**
     * {@see \Netresearch\NrLlm\Service\LlmServiceManager} calls this once per
     * call, before the pipeline starts.
     *
     * FIRST writer wins, unlike the complexity: the facts describe the request
     * the caller made, and a fallback re-send is the same request against a
     * different configuration. Re-measuring would produce the identical numbers
     * at best; letting a later write through would only create a way for them
     * to disagree.
     */
    public function recordRequestFacts(RequestFacts $facts): void
    {
        if ($this->requestFacts instanceof RequestFacts) {
            return;
        }

        $this->requestFacts = $facts;
    }

    /**
     * {@see UsageMiddleware} calls this with what the provider reported for the
     * call that answered.
     *
     * Last writer wins: on a fallback swap the sibling's response is the one
     * that was served, and its tokens are the ones that were spent.
     */
    public function recordCallUsage(ProviderCallUsage $usage): void
    {
        $this->callUsage = $usage;
    }
}
