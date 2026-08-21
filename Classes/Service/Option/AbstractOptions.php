<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Base class for typed option objects.
 *
 * Provides common functionality for all option classes including
 * array conversion, merging, and validation helpers.
 *
 * @api
 */
abstract class AbstractOptions
{
    /**
     * Optional request idempotency key (ADR-063). A repeated call carrying the
     * same key returns the stored result instead of calling the provider again
     * (see IdempotencyMiddleware). NOT a provider option — it is deliberately
     * kept out of {@see self::toArray()} so it is never sent to the provider;
     * the service layer forwards it as call metadata.
     */
    protected ?string $idempotencyKey = null;

    /**
     * Optional caller-supplied correlation id (ADR-176).
     *
     * Every send already gets one — {@see ProviderCallContext} generates a
     * UUID when none is passed — but it is generated inside the pipeline and
     * never handed back, so a caller cannot say afterwards which row on
     * tx_nrllm_telemetry was its own call. Supplying it here is the answer to
     * that: the caller knows the id because it chose it.
     *
     * That is what makes a per-call outcome recordable at all. It is also the
     * reason this is not a return value: {@see CompletionResponse} is frozen
     * (Tests/Unit/Api/api-surface.txt), so growing it would be a breaking
     * change, while an option is additive.
     *
     * Like the idempotency key it is deliberately kept out of
     * {@see self::toArray()} and never reaches a provider.
     */
    protected ?string $correlationId = null;

    /**
     * Convert options to array format for providers.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * Return a copy tagged with an idempotency key. A repeat call with the same
     * key is served from the idempotency store rather than re-hitting the
     * provider (ADR-063).
     */
    public function withIdempotencyKey(string $idempotencyKey): static
    {
        $clone = clone $this;
        $clone->idempotencyKey = $idempotencyKey;

        return $clone;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Return a copy that will be traced under the given correlation id.
     *
     * An empty string is ignored rather than stored: the pipeline treats it as
     * "none given" anyway, and keeping it would make the getter answer with a
     * value no telemetry row can ever carry.
     */
    public function withCorrelationId(string $correlationId): static
    {
        if ($correlationId === '') {
            return $this;
        }

        $clone = clone $this;
        $clone->correlationId = $correlationId;

        return $clone;
    }

    /**
     * Which piece of software makes this call (ADR-177). Like the idempotency
     * key, deliberately kept out of {@see self::toArray()}: it is call
     * metadata persisted on the telemetry row, never a provider option.
     */
    protected ?string $callerSourceExtension = null;

    /** The operation inside the calling software (ADR-177); '' = unspecified. */
    protected ?string $callerSourceOperation = null;

    /**
     * Return a copy tagged with the caller's identity — the extension key of
     * the calling software and optionally the operation inside it (e.g.
     * "ai_seo_helper" / "requestAi"). Persisted on the telemetry row as
     * source_extension / source_operation (ADR-177); never sent to the
     * provider.
     */
    public function withCallerSource(string $extension, string $operation = ''): static
    {
        $clone                        = clone $this;
        $clone->callerSourceExtension = $extension;
        $clone->callerSourceOperation = $operation;

        return $clone;
    }

    public function getCallerSourceExtension(): ?string
    {
        return $this->callerSourceExtension;
    }

    public function getCallerSourceOperation(): ?string
    {
        return $this->callerSourceOperation;
    }

    /**
     * Re-apply a caller identity (ADR-177) that arrived in a consumer-supplied
     * array. A `fromArray()` implementation reads its own keys and rebuilds the
     * object through the constructor, so anything the base class holds is lost
     * on the round trip unless it is put back here.
     *
     * This is deliberately NOT the mirror of {@see self::toArray()}. That method
     * builds the provider payload, and the caller identity is consumer metadata
     * that must never reach the provider — the same reason `configuration` and
     * the budget fields are absent there. The array `fromArray()` reads is the
     * consumer's own input, which is a different thing that happens to be shaped
     * alike.
     *
     * An absent or empty extension key leaves the object untouched, so an
     * unannotated call stays indistinguishable from a pre-feature one.
     *
     * @param array<string, mixed> $source
     */
    protected function withCallerSourceFromArray(array $source): static
    {
        $extension = $source['callerSourceExtension'] ?? null;
        if (!is_string($extension) || $extension === '') {
            return $this;
        }

        $operation = $source['callerSourceOperation'] ?? null;

        return $this->withCallerSource($extension, is_string($operation) ? $operation : '');
    }

    /**
     * Validate value is within numeric range.
     *
     * @throws InvalidArgumentException
     */
    protected static function validateRange(
        float|int $value,
        float|int $min,
        float|int $max,
        string $name,
    ): void {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(
                sprintf('%s must be between %s and %s, got %s', $name, $min, $max, $value),
                3976896171,
            );
        }
    }

    /**
     * Validate value is one of allowed options.
     *
     * @param array<int, string> $allowed
     *
     * @throws InvalidArgumentException
     */
    protected static function validateEnum(string $value, array $allowed, string $name): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                sprintf('%s must be one of: %s, got "%s"', $name, implode(', ', $allowed), $value),
                8287317140,
            );
        }
    }

    /**
     * Validate value is positive integer.
     *
     * @throws InvalidArgumentException
     */
    protected static function validatePositiveInt(int $value, string $name): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(
                sprintf('%s must be a positive integer, got %d', $name, $value),
                5622106267,
            );
        }
    }

    /**
     * Filter null values from array.
     *
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     */
    protected function filterNull(array $array): array
    {
        return array_filter($array, static fn($v): bool => $v !== null);
    }
}
