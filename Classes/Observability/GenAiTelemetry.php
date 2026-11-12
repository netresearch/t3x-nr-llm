<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Observability;

use Closure;
use Composer\InstalledVersions;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\DecisionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Provider\Middleware\TelemetrySignals;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function array_key_exists;
use function hrtime;
use function is_array;
use function is_string;

/**
 * The one place nr-llm talks to OpenTelemetry (ADR-291).
 *
 * It uses the vendor-neutral API only. The tracer and the meter come from
 * {@see Globals}, so whatever the host installation registered is what receives
 * the spans: an SDK with an exporter, or the no-op providers of the API package
 * when there is none. This class configures neither, and starts no exporter.
 * With no SDK, or with the extension setting `telemetry.openTelemetry` off,
 * every method calls its closure and returns, which is what
 * {@see self::isActive()} decides once per call.
 *
 * What it records is metadata: operation, provider, model names, token counts,
 * finish reasons, durations and the class name of an exception. It records no
 * prompt, no completion, no tool arguments or results, no exception message and
 * no URL. There is deliberately no opt-in for content; the convention's content
 * attributes are not emitted.
 *
 * Instrumentation never changes what the caller sees: every step that writes to
 * a span or an instrument is guarded, and an exception of the observed code is
 * re-thrown untouched.
 */
final readonly class GenAiTelemetry
{
    private CachedInstrumentation $instrumentation;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {
        $this->instrumentation = new CachedInstrumentation(
            GenAiSemconv::SCOPE_NAME,
            $this->packageVersion(),
            GenAiSemconv::SCHEMA_URL,
        );
    }

    /**
     * Whether anything would receive what is recorded: the setting is on and
     * the host registered a tracer or a meter provider of its own.
     */
    public function isActive(): bool
    {
        if (!$this->enabledBySetting()) {
            return false;
        }

        return !Globals::tracerProvider() instanceof NoopTracerProvider
            || !Globals::meterProvider() instanceof NoopMeterProvider;
    }

    /**
     * One CLIENT span for one attempt at one model, with the duration and the
     * token usage as metrics. The attempt is what the pipeline hands to its
     * innermost layers: the fallback middleware calls it again, with the
     * sibling's configuration, for every configuration of the chain.
     *
     * @param callable(ProviderCallContext): mixed $next
     */
    public function traceInference(ProviderCallContext $context, callable $next): mixed
    {
        $call = $this->startInference($context);
        if (!$call instanceof GenAiCall) {
            return $next($context);
        }

        $scope  = null;
        $result = null;
        $error  = null;

        try {
            $scope = $call->span->activate();
        } catch (Throwable) {
            // A span that cannot become current is no reason to fail the call.
        }

        try {
            $result = $next($context);
        } catch (Throwable $e) {
            $error = $e::class;

            throw $e;
        } finally {
            $this->guard(static function () use ($scope): void {
                $scope?->detach();
            });
            $this->finishInference($call, $context, $result, $error, null, false);
        }

        return $result;
    }

    /**
     * Begin the span of a streamed call. It is started but not activated: a
     * generator hands control to its consumer between two chunks, and a scope
     * held across a `yield` would leave this span current inside code that is
     * not part of the stream. The parent is the context of the code that asked
     * for the stream.
     */
    public function startStream(ProviderCallContext $context, ContextInterface $parent): ?GenAiCall
    {
        return $this->startInference($context, $parent);
    }

    /**
     * End the span of a streamed call and record its metrics.
     *
     * The stream gets no token counts: what the dispatcher counts is an
     * estimate from character lengths, and an estimate would be reported as a
     * measurement.
     *
     * @param ProviderCallContext $served The call's context bound to the configuration that answered
     */
    public function finishStream(
        ?GenAiCall $call,
        ProviderCallContext $served,
        ?string $errorClass,
        bool $abandoned,
        ?float $firstChunkSeconds,
    ): void {
        if (!$call instanceof GenAiCall) {
            return;
        }

        $this->finishInference($call, $served, null, $errorClass, $firstChunkSeconds, $abandoned);
    }

    /**
     * Starts a span and makes it current, for the caller that wraps a piece of
     * its own code in one. Returns null where nothing would receive it, and
     * never throws. The caller ends it with {@see ScopedSpan::finish()}.
     *
     * @param non-empty-string                               $name
     * @param SpanKind::KIND_*                               $kind
     * @param array<non-empty-string, bool|int|float|string> $attributes
     */
    public function begin(string $name, int $kind, array $attributes): ?ScopedSpan
    {
        if (!$this->isActive()) {
            return null;
        }

        try {
            $span = $this->instrumentation->tracer()
                ->spanBuilder($name)
                ->setSpanKind($kind)
                ->setAttributes($attributes)
                ->startSpan();
        } catch (Throwable) {
            return null;
        }

        try {
            return new ScopedSpan($span, $span->activate());
        } catch (Throwable) {
            return new ScopedSpan($span, null);
        }
    }

    /**
     * An INTERNAL span around the walk through a fallback chain, so the
     * attempts are children of one parent and the failed ones are events on it.
     * Only a call whose configuration has a chain gets this span.
     *
     * @template T
     *
     * @param Closure(): T $walk
     *
     * @return T
     */
    public function traceFallbackChain(ProviderCallContext $context, Closure $walk): mixed
    {
        $span = $this->begin(GenAiSemconv::SPAN_FALLBACK_CHAIN, SpanKind::KIND_INTERNAL, [
            GenAiSemconv::ATTR_CORRELATION_ID => $context->correlationId,
            GenAiSemconv::ATTR_CONFIGURATION  => $context->telemetryConfigurationIdentifier(),
        ]);
        if (!$span instanceof ScopedSpan) {
            return $walk();
        }

        $error = null;

        try {
            return $walk();
        } catch (Throwable $e) {
            $error = $e::class;

            throw $e;
        } finally {
            $signals = $context->telemetrySignals;
            $span->finish([
                GenAiSemconv::ATTR_FALLBACK_COUNT => $signals->fallbackAttempts,
                GenAiSemconv::ATTR_SERVED         => $signals->servedConfigurationIdentifier ?? $context->telemetryConfigurationIdentifier(),
            ], $error);
        }
    }

    /**
     * An event on the current span for one attempt of the chain that failed and
     * was passed over. Carries the configuration identifier and the class name
     * of the exception, never its message.
     */
    public function recordFallbackFailure(string $configurationIdentifier, Throwable $failure): void
    {
        if (!$this->isActive()) {
            return;
        }

        $this->guard(static function () use ($configurationIdentifier, $failure): void {
            Span::getCurrent()->addEvent(GenAiSemconv::EVENT_FALLBACK_FAILED, [
                GenAiSemconv::ATTR_CONFIGURATION => $configurationIdentifier,
                GenAiSemconv::ATTR_ERROR_TYPE    => $failure::class,
            ]);
        });
    }

    private function startInference(ProviderCallContext $context, ?ContextInterface $parent = null): ?GenAiCall
    {
        $operation = GenAiSemconv::operationName($context->operation);
        if ($operation === null || !$this->isActive()) {
            return null;
        }

        try {
            $builder = $this->instrumentation->tracer()
                ->spanBuilder($operation . ($context->telemetryModel() !== '' ? ' ' . $context->telemetryModel() : ''))
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute(GenAiSemconv::ATTR_OPERATION_NAME, $operation)
                ->setAttribute(GenAiSemconv::ATTR_PROVIDER_NAME, GenAiSemconv::providerName($context->telemetryProvider()));
            if ($context->telemetryModel() !== '') {
                $builder->setAttribute(GenAiSemconv::ATTR_REQUEST_MODEL, $context->telemetryModel());
            }

            if ($parent instanceof ContextInterface) {
                $builder->setParent($parent);
            }

            return new GenAiCall(
                $builder->startSpan(),
                $operation,
                hrtime(true),
                $context->telemetrySignals->fallbackAttempts,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function finishInference(
        GenAiCall $call,
        ProviderCallContext $context,
        mixed $result,
        ?string $errorClass,
        ?float $firstChunkSeconds,
        bool $abandoned,
    ): void {
        $this->guard(function () use ($call, $context, $result, $errorClass, $firstChunkSeconds, $abandoned): void {
            $facts     = $this->factsOf($result);
            $seconds   = (hrtime(true) - $call->startNs) / 1_000_000_000;
            $signals   = $context->telemetrySignals;
            $requested = $context->telemetryConfigurationIdentifier();
            $provider  = TelemetrySignals::orResolved($context->telemetryProvider(), $signals->resolvedProviderOf($requested));
            $provider  = GenAiSemconv::providerName($provider !== '' ? $provider : $facts['provider']);

            $model  = TelemetrySignals::orResolved($context->telemetryModel(), $signals->resolvedModelOf($requested));
            $stream = $context->operation === ProviderOperation::Stream;

            if ($firstChunkSeconds === null && $signals->timeToFirstTokenMs !== null) {
                $firstChunkSeconds = $signals->timeToFirstTokenMs / 1000;
                $stream            = true;
            }

            $span = $call->span;
            $span->setAttribute(GenAiSemconv::ATTR_PROVIDER_NAME, $provider);
            if ($model !== '') {
                $span->setAttribute(GenAiSemconv::ATTR_REQUEST_MODEL, $model);
                $span->updateName($call->operation . ' ' . $model);
            }

            if ($facts['model'] !== '') {
                $span->setAttribute(GenAiSemconv::ATTR_RESPONSE_MODEL, $facts['model']);
            }

            if ($facts['finish'] !== null && $facts['finish'] !== '') {
                $span->setAttribute(GenAiSemconv::ATTR_FINISH_REASONS, [$facts['finish']]);
            }

            if ($stream) {
                $span->setAttribute(GenAiSemconv::ATTR_REQUEST_STREAM, true);
            }

            if ($firstChunkSeconds !== null) {
                $span->setAttribute(GenAiSemconv::ATTR_FIRST_CHUNK, $firstChunkSeconds);
            }

            $span->setAttribute(GenAiSemconv::ATTR_CORRELATION_ID, $context->correlationId);
            if ($requested !== '') {
                $span->setAttribute(GenAiSemconv::ATTR_CONFIGURATION, $requested);
            }

            $span->setAttribute(GenAiSemconv::ATTR_FALLBACK_ATTEMPT, $call->fallbackAttempt);
            $source = $context->metadata[TelemetryMiddleware::METADATA_SOURCE_EXTENSION] ?? '';
            if (is_string($source) && $source !== '') {
                $span->setAttribute(GenAiSemconv::ATTR_SOURCE_EXTENSION, $source);
            }

            if ($abandoned) {
                $span->setAttribute(GenAiSemconv::ATTR_STREAM_ABANDONED, true);
            }

            $usage = $facts['usage'];
            $span->setAttributes(GenAiSemconv::usageAttributes($usage, $call->operation !== 'embeddings'));
            ScopedSpan::markError($span, $errorClass);
            $span->end();

            $this->recordMetrics($call->operation, $provider, $model, $facts['model'], $errorClass, $seconds, $firstChunkSeconds, $usage);
        });
    }

    private function recordMetrics(
        string $operation,
        string $provider,
        string $requestModel,
        string $responseModel,
        ?string $errorClass,
        float $seconds,
        ?float $firstChunkSeconds,
        ?UsageStatistics $usage,
    ): void {
        $meter = $this->instrumentation->meter();

        $attributes = [
            GenAiSemconv::ATTR_OPERATION_NAME => $operation,
            GenAiSemconv::ATTR_PROVIDER_NAME  => $provider,
        ];
        if ($requestModel !== '') {
            $attributes[GenAiSemconv::ATTR_REQUEST_MODEL] = $requestModel;
        }

        if ($responseModel !== '') {
            $attributes[GenAiSemconv::ATTR_RESPONSE_MODEL] = $responseModel;
        }

        $durationAttributes = $attributes;
        if ($errorClass !== null && $errorClass !== '') {
            $durationAttributes[GenAiSemconv::ATTR_ERROR_TYPE] = $errorClass;
        }

        $meter->createHistogram(GenAiSemconv::METRIC_OPERATION_DURATION, 's', 'GenAI operation duration.', ['ExplicitBucketBoundaries' => GenAiSemconv::DURATION_BUCKETS])
            ->record($seconds, $durationAttributes);

        if ($firstChunkSeconds !== null) {
            $meter->createHistogram(GenAiSemconv::METRIC_FIRST_CHUNK, 's', 'Time to receive the first chunk in a streaming operation.', ['ExplicitBucketBoundaries' => GenAiSemconv::DURATION_BUCKETS])
                ->record($firstChunkSeconds, $attributes);
        }

        if (!$usage instanceof UsageStatistics) {
            return;
        }

        $tokens = $meter->createHistogram(GenAiSemconv::METRIC_TOKEN_USAGE, '{token}', 'Number of input and output tokens used.', ['ExplicitBucketBoundaries' => GenAiSemconv::TOKEN_BUCKETS]);
        if ($usage->isPromptTokensReported()) {
            $tokens->record($usage->promptTokens, [...$attributes, GenAiSemconv::ATTR_TOKEN_TYPE => GenAiSemconv::TOKEN_TYPE_INPUT]);
        }

        if ($operation !== 'embeddings' && $usage->isCompletionTokensReported()) {
            $tokens->record($usage->completionTokens, [...$attributes, GenAiSemconv::ATTR_TOKEN_TYPE => GenAiSemconv::TOKEN_TYPE_OUTPUT]);
        }
    }

    /**
     * What a provider call returned, as far as it matters here: the typed
     * response, or the array shape the answer cache's codec produces.
     *
     * @return array{usage: ?UsageStatistics, model: string, finish: ?string, provider: string}
     */
    private function factsOf(mixed $result): array
    {
        $none = ['usage' => null, 'model' => '', 'finish' => null, 'provider' => ''];

        if ($result instanceof CompletionResponse) {
            return ['usage' => $result->usage, 'model' => $result->model, 'finish' => $result->finishReason, 'provider' => $result->provider];
        }

        if ($result instanceof EmbeddingResponse || $result instanceof VisionResponse || $result instanceof DecisionResponse) {
            return ['usage' => $result->usage, 'model' => $result->model, 'finish' => null, 'provider' => $result->provider];
        }

        if (is_array($result) && is_array($result['usage'] ?? null)) {
            /** @var array<string, mixed> $usage */
            $usage    = $result['usage'];
            $model    = $result['model'] ?? '';
            $provider = $result['provider'] ?? '';

            return [
                'usage'    => UsageStatistics::fromArray($usage),
                'model'    => is_string($model) ? $model : '',
                'finish'   => null,
                'provider' => is_string($provider) ? $provider : '',
            ];
        }

        return $none;
    }

    private function enabledBySetting(): bool
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return true;
        }

        $telemetry = is_array($config['telemetry'] ?? null) ? $config['telemetry'] : [];
        if (!array_key_exists('openTelemetry', $telemetry)) {
            return true;
        }

        // Extension configuration delivers booleans as '0' / '1' strings.
        return (bool) $telemetry['openTelemetry'];
    }

    /**
     * Runs a step of the instrumentation. Nothing it does may reach the code
     * it observes.
     *
     * @param Closure(): void $step
     */
    private function guard(Closure $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            // Observability must not break the call it observes.
        }
    }

    private function packageVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('netresearch/nr-llm');
        } catch (Throwable) {
            return null;
        }
    }
}
