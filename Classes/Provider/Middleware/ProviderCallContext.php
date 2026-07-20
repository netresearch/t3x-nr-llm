<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable context threaded through a MiddlewarePipeline invocation.
 *
 * Carries what every middleware needs to know about the call without leaking
 * the operation-specific payload (messages, embeddings input, tool specs). The
 * payload stays captured in the terminal callable, which lets each feature
 * service keep its typed signature (ADR-026).
 *
 * The configuration it runs against lives on the context, not as a separate
 * pipeline parameter, so a caller that has no {@see LlmConfiguration} entity —
 * an image or speech service identified only by provider/model strings — can
 * still drive the same pipeline (ADR-096). When the configuration is null those
 * string fields are the source of truth for telemetry; when it is present they
 * are ignored and the entity wins. {@see FallbackMiddleware} swaps the
 * configuration on a retryable failure via {@see self::withConfiguration()}.
 */
final readonly class ProviderCallContext
{
    /**
     * @param string               $provider                provider identifier for telemetry when no configuration entity is present
     * @param string               $model                   model identifier for telemetry when no configuration entity is present
     * @param string               $configurationIdentifier configuration identifier for telemetry when no configuration entity is present
     * @param array<string, mixed> $metadata                additional cross-cutting data (user id for budget checks, cache-key inputs, trace tags)
     * @param TelemetrySignals     $telemetrySignals        mutable scratchpad an inner middleware uses to signal the outer TelemetryMiddleware within this run (cache hit, fallback attempts). Default-constructed per call, never shared across contexts.
     */
    public function __construct(
        public ProviderOperation $operation,
        public string $correlationId,
        public ?LlmConfiguration $configuration = null,
        public string $provider = '',
        public string $model = '',
        public string $configurationIdentifier = '',
        public array $metadata = [],
        public TelemetrySignals $telemetrySignals = new TelemetrySignals(),
    ) {}

    /**
     * A context for a generic call with an auto-generated correlation id and no
     * configuration entity yet (the pipeline resolves or the caller sets one).
     *
     * @param array<string, mixed> $metadata
     */
    public static function for(ProviderOperation $operation, array $metadata = []): self
    {
        return new self(
            operation: $operation,
            correlationId: Uuid::v4()->toRfc4122(),
            metadata: $metadata,
        );
    }

    /**
     * A context bound to a configuration entity (the chat/embedding/vision
     * path). The provider/model/identifier strings are left empty — the entity
     * is the source of truth while it is present.
     *
     * @param array<string, mixed> $metadata
     */
    public static function forConfiguration(ProviderOperation $operation, LlmConfiguration $configuration, array $metadata = []): self
    {
        return new self(
            operation: $operation,
            correlationId: Uuid::v4()->toRfc4122(),
            configuration: $configuration,
            metadata: $metadata,
        );
    }

    /**
     * A context for a service call identified only by provider/model strings —
     * an image, speech or translation service with no {@see LlmConfiguration}
     * entity. Telemetry reads these strings.
     *
     * @param array<string, mixed> $metadata
     */
    public static function forService(
        ProviderOperation $operation,
        string $provider,
        string $model,
        string $configurationIdentifier = '',
        array $metadata = [],
    ): self {
        return new self(
            operation: $operation,
            correlationId: Uuid::v4()->toRfc4122(),
            provider: $provider,
            model: $model,
            configurationIdentifier: $configurationIdentifier,
            metadata: $metadata,
        );
    }

    /**
     * Return a copy bound to a different configuration — used by
     * {@see FallbackMiddleware} to route the call to a sibling configuration
     * while keeping the correlation id and the accumulated telemetry signals.
     */
    public function withConfiguration(?LlmConfiguration $configuration): self
    {
        return new self(
            operation: $this->operation,
            correlationId: $this->correlationId,
            configuration: $configuration,
            provider: $this->provider,
            model: $this->model,
            configurationIdentifier: $this->configurationIdentifier,
            metadata: $this->metadata,
            telemetrySignals: $this->telemetrySignals,
        );
    }

    /**
     * Return a copy with the given metadata merged on top of the current map,
     * carrying the SAME telemetry-signal sink forward so a context re-derived
     * mid-run does not drop the cache-hit / fallback signals collected against
     * the original.
     *
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            operation: $this->operation,
            correlationId: $this->correlationId,
            configuration: $this->configuration,
            provider: $this->provider,
            model: $this->model,
            configurationIdentifier: $this->configurationIdentifier,
            metadata: [...$this->metadata, ...$metadata],
            telemetrySignals: $this->telemetrySignals,
        );
    }

    /**
     * The provider identifier for telemetry: the configuration entity's when
     * present, else the string carried on the context.
     */
    public function telemetryProvider(): string
    {
        return $this->configuration?->getProviderType() ?? $this->provider;
    }

    /**
     * The model identifier for telemetry: the configuration entity's when
     * present, else the string carried on the context.
     */
    public function telemetryModel(): string
    {
        return $this->configuration?->getModelId() ?? $this->model;
    }

    /**
     * The configuration identifier for telemetry: the configuration entity's
     * when present, else the string carried on the context.
     */
    public function telemetryConfigurationIdentifier(): string
    {
        return $this->configuration?->getIdentifier() ?? $this->configurationIdentifier;
    }
}
