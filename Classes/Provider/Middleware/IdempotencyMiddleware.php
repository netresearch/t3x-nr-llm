<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Generator;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Request idempotency keyed by a caller-supplied token (ADR-063).
 *
 * When a call carries an idempotency key (via
 * {@see self::METADATA_IDEMPOTENCY_KEY} on the context metadata, set from an
 * option's `withIdempotencyKey()`), the FIRST call runs normally and its result
 * is stored under that key; a later REPEAT with the same key returns the stored
 * result without calling the provider again. This makes a SEQUENTIAL retry — a
 * network retry, or a re-submit after the first request already returned — safe:
 * it neither re-charges the budget nor produces a second, different completion.
 *
 * Scope — sequential retries only. The lookup is a plain get-then-run-then-set
 * with no atomic reservation, so two requests carrying the SAME key that are
 * genuinely in flight at once (both arriving before either has stored its
 * result) both miss and both execute; the dedup only engages once a result is
 * stored. Closing that window needs an atomic reserve-on-miss, which TYPO3's
 * cache {@see FrontendInterface} does not expose — a future revision could gate
 * the run+store with {@see \TYPO3\CMS\Core\Locking\LockFactory} (ADR-063).
 *
 * Opt-in: with no idempotency key the middleware is a verbatim pass-through, so
 * ordinary (non-idempotent) traffic is untouched.
 *
 * Pipeline placement — priority 105, just inside TelemetryMiddleware (110) and
 * OUTSIDE CacheMiddleware (100):
 *
 *   TelemetryMiddleware      <-- 110  observes every run, replays included
 *     IdempotencyMiddleware  <-- 105  replays a stored result by key   (THIS)
 *       CacheMiddleware      <-- 100  payload cache
 *         ...                <--      budget / fallback / usage / circuit
 *
 * Outside Cache/Budget so a replay short-circuits the whole behavioural stack
 * (no second budget charge), yet inside Telemetry so a replay is still recorded
 * as a (fast) run.
 *
 * Why not reuse CacheMiddleware's key? CacheMiddleware is deliberately
 * array-only (it persists `array<string, mixed>`), so it cannot round-trip the
 * typed responses (CompletionResponse, …) the chat/completion paths return.
 * This middleware stores over its own `nrllm_idempotency` VariableFrontend,
 * which serialises any response value — so idempotency works for every
 * operation, not just the array-shaped ones — while still being cache-backed
 * (no new table): idempotency results are transient and TTL-bounded by nature.
 *
 * Streaming responses (Generator) are never stored: a generator cannot be
 * serialised, and streaming stays out of the pipeline anyway (ADR-026). Failed
 * calls are not stored either — the exception propagates and a later retry with
 * the same key genuinely re-attempts.
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME, attributes: ['priority' => 105])]
final class IdempotencyMiddleware implements ProviderMiddlewareInterface
{
    public const METADATA_IDEMPOTENCY_KEY = 'idempotencyKey';

    private const CACHE_IDENTIFIER = 'nrllm_idempotency';

    /** Idempotency window: how long a stored result answers a repeated key. */
    private const TTL_SECONDS = 86400;

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly Typo3CacheManager $cacheManager,
    ) {}

    /**
     * @param callable(LlmConfiguration): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed {
        $key = $this->readKey($context);
        if ($key === null) {
            return $next($configuration);
        }

        $entryId = $this->entryIdentifier($key);

        $stored = $this->safeGet($entryId);
        if ($stored !== null) {
            return $stored;
        }

        $result = $next($configuration);

        if ($this->isStorable($result)) {
            $this->safeSet($entryId, $result);
        }

        return $result;
    }

    private function readKey(ProviderCallContext $context): ?string
    {
        $value = $context->metadata[self::METADATA_IDEMPOTENCY_KEY] ?? null;
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * A stored value is only ever a previously computed, non-streaming result.
     * Generators cannot be serialised (and streaming is not routed through the
     * pipeline anyway).
     */
    private function isStorable(mixed $result): bool
    {
        return !$result instanceof Generator;
    }

    private function safeGet(string $entryId): mixed
    {
        try {
            $stored = $this->getCache()->get($entryId);
        } catch (Throwable) {
            return null;
        }

        // VariableFrontend::get() returns false on a miss; a stored value is
        // never the boolean false (responses are objects/arrays).
        return $stored === false ? null : $stored;
    }

    private function safeSet(string $entryId, mixed $result): void
    {
        try {
            $this->getCache()->set($entryId, $result, [], self::TTL_SECONDS);
        } catch (Throwable) {
            // Idempotency is best-effort: a store failure must not break the call.
        }
    }

    /**
     * Map the caller-supplied key (arbitrary text) to a valid cache entry
     * identifier: a readable, sanitised prefix plus a hash of the raw key so
     * distinct keys that sanitise alike cannot collide.
     */
    private function entryIdentifier(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) ?? '';

        return 'idem_' . substr($safe, 0, 64) . '_' . substr(hash('sha256', $key), 0, 16);
    }

    private function getCache(): FrontendInterface
    {
        if (!$this->cache instanceof FrontendInterface) {
            $this->cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        }

        return $this->cache;
    }
}
