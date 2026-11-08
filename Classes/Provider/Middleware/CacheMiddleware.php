<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use JsonException;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelsInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function trim;

/**
 * Array-payload cache middleware.
 *
 * Sits between the caller and a provider call to skip the call entirely
 * when the same request has been answered before. Opt-in per call: the
 * middleware does nothing unless the caller supplies a cache key via
 * ProviderCallContext metadata. This keeps non-deterministic operations
 * (stateful chat, vision) untouched even when this middleware is
 * registered in the pipeline.
 *
 * Caller responsibilities (usually a feature service):
 *  1. Compute a stable cache key from the provider id + operation +
 *     inputs + relevant options. Put it on the context metadata under
 *     `CacheMiddleware::METADATA_CACHE_KEY`. Absent / empty = skip.
 *  2. Optionally put a TTL (`METADATA_CACHE_TTL`, int seconds) and
 *     cache tags (`METADATA_CACHE_TAGS`, list<string>) on the context
 *     so the store call is parameterised correctly.
 *  3. Wrap the terminal so it returns an `array<string, mixed>`, which
 *     is the shape CacheManager stores. Non-array return values are
 *     never written to cache — the middleware quietly passes them
 *     through on miss so callers who do not opt in to serialisation
 *     are not surprised by empty cache slots.
 *
 * Why array-only
 * --------------
 * The TYPO3 cache framework (via CacheManager here) persists arrays,
 * not arbitrary objects. Typed response objects
 * (CompletionResponse / EmbeddingResponse / VisionResponse) therefore
 * live at the feature-service boundary: the feature service wraps the
 * provider call so the terminal returns `$response->toArray()`, then
 * reconstructs the typed response from whatever the pipeline returns
 * (cached array or freshly-computed array). Keeping the middleware
 * opinion-free on response shape is what lets it work for any future
 * operation without a per-type codec.
 *
 * Pipeline ordering
 * -----------------
 * Cache is the outermost BEHAVIOURAL layer so a cache hit skips even the
 * fallback attempt and is NOT counted against a user's budget. Only the
 * observation-only TelemetryMiddleware (priority 110) sits further out, so a
 * cache-served response still produces a telemetry row; on a hit this
 * middleware flags that row via the shared telemetry signals. This order is
 * pinned via the tag priority below (highest priority = first in the autowired
 * iterator = outermost in the pipeline):
 *
 *   TelemetryMiddleware        <-- outermost; observation only      (priority 110)
 *     IdempotencyMiddleware    <-- replays a stored result by key    (priority 105)
 *       CacheMiddleware        <-- short-circuits on hit             (priority 100)
 *         GuardrailMiddleware  <-- screens/redacts the response      (priority 90)
 *           BudgetMiddleware   <-- pre-flight only on miss           (priority 75)
 *             FallbackMiddleware <-- swaps config on retryable failure (priority 50)
 *               UsageMiddleware  <-- records the call that actually ran (priority 25)
 *                 CircuitBreaker <-- guards the provider call        (priority 20)
 *                   <terminal>
 *
 * Callers who want cache accounting (count hits against budget / usage)
 * should assemble a custom pipeline with Cache *inside* those layers — it is
 * a pipeline-assembly choice, not a property of this middleware.
 *
 * Answers (ADR-249)
 * -----------------
 * One typed response is the exception to array-only: a chat or completion
 * answer, for a call {@see \Netresearch\NrLlm\Service\CallMetadataFactory::answerCache()}
 * marked with {@see self::METADATA_ANSWER_CACHE}. The mark, not the key, opts
 * in, so a caller's own key on a chat call keeps its old effect: none. Only a
 * complete text answer is kept (finish reason `stop`, no tool calls, not
 * empty, and valid JSON when the call asked for JSON mode), and only where the privacy level is `full` for the provider that
 * served the answer — which can differ from the configuration's for a model
 * chosen by criteria or a fallback — because the entry is the answer verbatim.
 * A replay asks that provider's level again, carries no usage — it consumed
 * nothing — and says `answerCache: hit` in its metadata.
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME)]
final readonly class CacheMiddleware implements ProviderMiddlewareInterface
{
    /**
     * Pipeline priority, read by the tagged iterator via
     * `defaultPriorityMethod` (ADR-085 ordering).
     *
     * It lives in code rather than in the AutoconfigureTag attribute
     * because an attribute priority is lost when the same tag is
     * declared on both the interface and the class and the container
     * deduplicates the two — which silently unsorts the whole pipeline.
     */
    public static function getDefaultPriority(): int
    {
        return 100;
    }

    public const METADATA_CACHE_KEY = 'cacheKey';

    public const METADATA_CACHE_TTL = 'cacheTtl';

    public const METADATA_CACHE_TAGS = 'cacheTags';

    /** Marks the entry as a chat or completion answer (ADR-249). */
    public const METADATA_ANSWER_CACHE = 'answerCache';

    /** Keep only an answer that parses as JSON: the call asked for JSON mode. */
    public const METADATA_ANSWER_CACHE_JSON = 'answerCacheJson';

    private const ANSWER_ENTRY = 'answer';

    private const DEFAULT_TTL_SECONDS = 3600;

    public function __construct(
        private CacheManagerInterface $cache,
        // Answers what an answer entry may store (ADR-242, ADR-249). Absent,
        // no answer is cached: the question cannot be asked, so the content
        // is not kept.
        private ?RunPrivacyLevelsInterface $privacyLevels = null,
        private GuardrailMiddleware $outputGuardrails = new GuardrailMiddleware([]),
    ) {}

    /**
     * @param callable(ProviderCallContext): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        callable $next,
    ): mixed {
        $key = $this->readKey($context);
        if ($key === null) {
            return $next($context);
        }

        if (($context->metadata[self::METADATA_ANSWER_CACHE] ?? false) === true) {
            return $this->handleAnswer($key, $context, $next);
        }

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            // Signal the outer TelemetryMiddleware (ADR-058) that this run was
            // served from cache. The context is shared across the pipeline, so
            // annotating its mutable signal sink is visible after the unwind.
            $context->telemetrySignals->recordCacheHit();

            return $cached;
        }

        $result = $next($context);

        if (is_array($result)) {
            /** @var array<string, mixed> $result */
            $this->cache->set(
                $key,
                $result,
                $this->readTtl($context),
                $this->readTags($context),
            );
        }

        return $result;
    }

    /**
     * @param callable(ProviderCallContext): mixed $next
     */
    private function handleAnswer(string $key, ProviderCallContext $context, callable $next): mixed
    {
        $replay = $this->replay($this->cache->get($key));
        if ($replay instanceof CompletionResponse && $this->servedWhereContentMayStay($replay)) {
            // The cached answer is screened under the current policy. A RETRY
            // verdict asks for a fresh answer, so the second call of this
            // closure goes to the provider as a miss would, instead of
            // re-screening the same cached text.
            // The hit is counted once the screen has passed the cached answer,
            // so a blocked or retried replay is not reported as served.
            $replayed  = false;
            $refetched = false;
            $result    = $this->outputGuardrails->handle(
                $context,
                function (ProviderCallContext $screenContext) use (&$replayed, &$refetched, $replay, $key, $next): mixed {
                    if ($replayed) {
                        $refetched = true;

                        return $this->fetchAndStore($key, $screenContext, $next);
                    }

                    $replayed = true;

                    return $replay;
                },
            );
            if (!$refetched) {
                $context->telemetrySignals->recordCacheHit();
            }

            return $result;
        }

        return $this->fetchAndStore($key, $context, $next);
    }

    /**
     * @param callable(ProviderCallContext): mixed $next
     */
    private function fetchAndStore(string $key, ProviderCallContext $context, callable $next): mixed
    {
        $result = $next($context);
        if ($result instanceof CompletionResponse && $this->isCompleteText($result, $context) && $this->servedWhereContentMayStay($result)) {
            $this->cache->set($key, [self::ANSWER_ENTRY => [
                'content'      => $result->content,
                'model'        => $result->model,
                'finishReason' => $result->finishReason,
                'provider'     => $result->provider,
                'metadata'     => $result->metadata,
                'thinking'     => $result->thinking,
            ]], $this->readTtl($context), $this->readTags($context));
        }

        return $result;
    }

    /**
     * The provider that actually answered, from the provenance the terminal
     * attaches (ADR-220). A model chosen by criteria and a fallback can answer
     * on a provider other than the configuration's; an answer that does not say
     * where it came from is not kept. The level of the configuration's own
     * provider is not what decides: the entry holds what the serving provider
     * wrote, and that provider's level is the global one tightened by it.
     */
    private function servedWhereContentMayStay(CompletionResponse $response): bool
    {
        $provenance = GeneratorProvenance::fromArray($response->metadata[GeneratorProvenance::METADATA_KEY] ?? null);

        return $provenance instanceof GeneratorProvenance
            && $this->privacyLevels?->forProvider($provenance->providerIdentifier) === PrivacyLevel::FULL;
    }

    private function isCompleteText(CompletionResponse $response, ProviderCallContext $context): bool
    {
        if ($response->finishReason !== 'stop' || $response->hasToolCalls() || trim($response->content) === '') {
            return false;
        }

        // A JSON-mode caller throws on an answer that does not parse; kept, it
        // would throw on every repeat until the entry expires.
        if (($context->metadata[self::METADATA_ANSWER_CACHE_JSON] ?? false) !== true) {
            return true;
        }

        try {
            json_decode($response->content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $cached
     */
    private function replay(?array $cached): ?CompletionResponse
    {
        $entry = $cached[self::ANSWER_ENTRY] ?? null;
        if (!is_array($entry) || !is_string($entry['content'] ?? null) || !is_string($entry['model'] ?? null)) {
            return null;
        }

        $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
        /** @var array<string, mixed> $metadata */
        $metadata[self::METADATA_ANSWER_CACHE] = 'hit';

        return new CompletionResponse(
            content: $entry['content'],
            model: $entry['model'],
            usage: new UsageStatistics(0, 0, 0),
            finishReason: is_string($entry['finishReason'] ?? null) ? $entry['finishReason'] : 'stop',
            provider: is_string($entry['provider'] ?? null) ? $entry['provider'] : '',
            metadata: $metadata,
            thinking: is_string($entry['thinking'] ?? null) ? $entry['thinking'] : null,
        );
    }

    private function readKey(ProviderCallContext $context): ?string
    {
        $value = $context->metadata[self::METADATA_CACHE_KEY] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function readTtl(ProviderCallContext $context): int
    {
        $value = $context->metadata[self::METADATA_CACHE_TTL] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return self::DEFAULT_TTL_SECONDS;
    }

    /**
     * @return array<int, string>
     */
    private function readTags(ProviderCallContext $context): array
    {
        $value = $context->metadata[self::METADATA_CACHE_TAGS] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $tag) {
            if (is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
