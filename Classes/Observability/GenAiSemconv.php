<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Observability;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;

/**
 * Every name the OpenTelemetry instrumentation emits (ADR-291).
 *
 * The GenAI conventions are still in development, and their home moved to
 * open-telemetry/semantic-conventions-genai, whose main branch renames the
 * metrics (`gen_ai.client.inference.*`) without a release. The names here are
 * those of the last release that defined them, semantic-conventions v1.41.0.
 * They live in this one class, so a rename on the way to a stable convention
 * is a change to this file and the documentation page that lists them.
 */
final class GenAiSemconv
{
    public const SCOPE_NAME = 'netresearch/nr-llm';

    /** The released convention these names follow. */
    public const SEMCONV_VERSION = '1.41.0';

    public const SCHEMA_URL = 'https://opentelemetry.io/schemas/' . self::SEMCONV_VERSION;

    // Attributes of the convention.
    public const ATTR_OPERATION_NAME = 'gen_ai.operation.name';

    public const ATTR_PROVIDER_NAME = 'gen_ai.provider.name';

    public const ATTR_REQUEST_MODEL = 'gen_ai.request.model';

    public const ATTR_REQUEST_STREAM = 'gen_ai.request.stream';

    public const ATTR_RESPONSE_MODEL = 'gen_ai.response.model';

    public const ATTR_FINISH_REASONS = 'gen_ai.response.finish_reasons';

    public const ATTR_FIRST_CHUNK = 'gen_ai.response.time_to_first_chunk';

    public const ATTR_INPUT_TOKENS = 'gen_ai.usage.input_tokens';

    public const ATTR_OUTPUT_TOKENS = 'gen_ai.usage.output_tokens';

    public const ATTR_TOKEN_TYPE = 'gen_ai.token.type';

    public const ATTR_TOOL_NAME = 'gen_ai.tool.name';

    public const ATTR_TOOL_TYPE = 'gen_ai.tool.type';

    public const ATTR_TOOL_CALL_ID = 'gen_ai.tool.call.id';

    public const ATTR_AGENT_NAME = 'gen_ai.agent.name';

    public const ATTR_ERROR_TYPE = 'error.type';

    // Attributes of this extension, namespaced as the convention asks of vendors.
    public const ATTR_CORRELATION_ID = 'nr_llm.correlation_id';

    public const ATTR_CONFIGURATION = 'nr_llm.configuration';

    public const ATTR_SERVED = 'nr_llm.configuration.served';

    public const ATTR_FALLBACK_ATTEMPT = 'nr_llm.fallback.attempt';

    public const ATTR_FALLBACK_COUNT = 'nr_llm.fallback.attempts';

    public const ATTR_SOURCE_EXTENSION = 'nr_llm.source_extension';

    public const ATTR_RUN_ITERATIONS = 'nr_llm.run.iterations';

    public const ATTR_RUN_OUTCOME = 'nr_llm.run.outcome';

    public const ATTR_RUN_TRUNCATED = 'nr_llm.run.truncated';

    public const ATTR_STREAM_ABANDONED = 'nr_llm.stream.abandoned';

    public const EVENT_FALLBACK_FAILED = 'nr_llm.fallback.attempt_failed';

    public const SPAN_FALLBACK_CHAIN = 'nr_llm.fallback_chain';

    public const OPERATION_EXECUTE_TOOL = 'execute_tool';

    public const OPERATION_INVOKE_AGENT = 'invoke_agent';

    public const TOKEN_TYPE_INPUT = 'input';

    public const TOKEN_TYPE_OUTPUT = 'output';

    public const TOOL_TYPE_FUNCTION = 'function';

    public const TOOL_TYPE_EXTENSION = 'extension';

    // Metrics.
    public const METRIC_TOKEN_USAGE = 'gen_ai.client.token.usage';

    public const METRIC_OPERATION_DURATION = 'gen_ai.client.operation.duration';

    public const METRIC_FIRST_CHUNK = 'gen_ai.client.operation.time_to_first_chunk';

    /** The advisory bucket boundaries of the convention, in seconds. */
    public const DURATION_BUCKETS = [0.01, 0.02, 0.04, 0.08, 0.16, 0.32, 0.64, 1.28, 2.56, 5.12, 10.24, 20.48, 40.96, 81.92];

    /** The advisory bucket boundaries of the token histogram. */
    public const TOKEN_BUCKETS = [1, 4, 16, 64, 256, 1024, 4096, 16384, 65536, 262144, 1048576, 4194304, 16777216, 67108864];

    /**
     * The `gen_ai.operation.name` of a pipeline operation; null for an
     * operation that is no AI call (a provider status lookup), which gets no
     * span.
     *
     * Well-known values where the convention has one. The specialised
     * services have none, and the convention allows a custom value there.
     *
     * @return non-empty-string|null
     */
    public static function operationName(ProviderOperation $operation): ?string
    {
        return match ($operation) {
            ProviderOperation::Chat,
            ProviderOperation::Completion,
            ProviderOperation::Vision,
            ProviderOperation::Tools,
            ProviderOperation::Stream          => 'chat',
            ProviderOperation::Embedding       => 'embeddings',
            ProviderOperation::Decision        => 'decision',
            ProviderOperation::ImageGeneration => 'image_generation',
            ProviderOperation::ImageEdit       => 'image_edit',
            ProviderOperation::ImageVariation  => 'image_variation',
            ProviderOperation::Transcription   => 'transcription',
            ProviderOperation::SpeechSynthesis => 'speech_synthesis',
            ProviderOperation::Translation     => 'translation',
            ProviderOperation::Metadata        => null,
        };
    }

    /**
     * The token counts of a span: the figures the provider reported, and no
     * 0 for a figure it did not report.
     *
     * @return array<non-empty-string, int>
     */
    public static function usageAttributes(?UsageStatistics $usage, bool $withOutput): array
    {
        $attributes = [];
        if ($usage instanceof UsageStatistics && $usage->isPromptTokensReported()) {
            $attributes[self::ATTR_INPUT_TOKENS] = $usage->promptTokens;
        }

        if ($withOutput && $usage instanceof UsageStatistics && $usage->isCompletionTokensReported()) {
            $attributes[self::ATTR_OUTPUT_TOKENS] = $usage->completionTokens;
        }

        return $attributes;
    }

    /**
     * The `gen_ai.provider.name` for an adapter type: the well-known value
     * where the convention names one, else the adapter type itself, which the
     * convention allows for a provider it does not list.
     */
    public static function providerName(string $adapterType): string
    {
        return match ($adapterType) {
            ''             => 'unknown',
            'gemini'       => 'gcp.gemini',
            'mistral'      => 'mistral_ai',
            'azure_openai' => 'azure.ai.openai',
            default        => $adapterType,
        };
    }
}
