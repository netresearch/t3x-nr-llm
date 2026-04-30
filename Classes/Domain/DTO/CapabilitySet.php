<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\DTO;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;

/**
 * Typed value object for a model's capability set (REC #6 slice 16a).
 *
 * `Model::$capabilities` (a CSV string in the database, audit `Model.php:51`)
 * is currently only exposed as `string`, `string[]`, or `list<ModelCapability>`
 * — every caller has to know which to ask for and string-typed callers can
 * easily forget to validate against the enum. This DTO is the typed
 * runtime representation: it wraps a deduplicated, ordered
 * `list<ModelCapability>` and answers every question controllers and
 * services actually have ("does it support `chat`?", "is it disjoint with
 * X?", "give me the union with Y").
 *
 * CSV serialisation stays a persistence concern of the entity — the DTO
 * round-trips via `fromCsv()` / `toCsv()` and never reaches the database
 * itself in this slice. Callers that previously held `string[]` should
 * migrate to this DTO; the legacy string accessors on `Model` are kept
 * for back-compat (slice 16b will migrate them caller-by-caller).
 *
 * Construction is invariant-safe: invalid CSV tokens (typos, capitalisation
 * drift, stray whitespace, removed-but-still-stored capabilities like a
 * future `OLD_NAME → NEW_NAME` rename) are dropped silently — same
 * defensive posture as `Model::getCapabilitiesAsEnums()` already takes.
 * Callers that need to know about invalid tokens should validate against
 * `ModelCapability::isValid()` before construction.
 */
final readonly class CapabilitySet implements JsonSerializable
{
    /**
     * @param list<ModelCapability> $capabilities Deduplicated, order-preserving
     */
    public function __construct(
        public array $capabilities = [],
    ) {}

    /**
     * Build from the CSV string that the entity persists.
     *
     * Empty input yields an empty set. Whitespace around tokens is
     * trimmed; unknown tokens are dropped (defensive against schema
     * drift); duplicates after normalisation are dropped.
     */
    public static function fromCsv(string $csv): self
    {
        if ($csv === '') {
            return new self();
        }
        return self::fromArray(explode(',', $csv));
    }

    /**
     * Build from an arbitrary array of strings or already-typed enums.
     *
     * @param array<int|string, mixed> $tokens
     */
    public static function fromArray(array $tokens): self
    {
        $seen = [];
        $out  = [];
        foreach ($tokens as $token) {
            $enum = match (true) {
                $token instanceof ModelCapability => $token,
                is_string($token)                 => ModelCapability::tryFrom(trim($token)),
                default                           => null,
            };
            if ($enum === null || isset($seen[$enum->value])) {
                continue;
            }
            $seen[$enum->value] = true;
            $out[]              = $enum;
        }
        return new self($out);
    }

    /**
     * @return list<string>
     */
    public function toStringList(): array
    {
        return array_map(static fn(ModelCapability $cap): string => $cap->value, $this->capabilities);
    }

    public function toCsv(): string
    {
        return implode(',', $this->toStringList());
    }

    public function isEmpty(): bool
    {
        return $this->capabilities === [];
    }

    public function count(): int
    {
        return count($this->capabilities);
    }

    /**
     * Membership check. Accepts both the typed enum and the legacy
     * string form so callers in transition (slice 16b will migrate
     * them) do not need an immediate change.
     */
    public function has(ModelCapability|string $capability): bool
    {
        $needle = $capability instanceof ModelCapability ? $capability : ModelCapability::tryFrom($capability);
        if ($needle === null) {
            return false;
        }
        foreach ($this->capabilities as $cap) {
            if ($cap === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return a new set with the given capability added (idempotent).
     * Strings are looked up against the enum; unknown strings yield
     * an unchanged set rather than a silent corruption.
     */
    public function with(ModelCapability|string $capability): self
    {
        $enum = $capability instanceof ModelCapability ? $capability : ModelCapability::tryFrom($capability);
        if ($enum === null || $this->has($enum)) {
            return $this;
        }
        return new self([...$this->capabilities, $enum]);
    }

    /**
     * Return a new set without the given capability. No-op when the
     * capability is absent.
     */
    public function without(ModelCapability|string $capability): self
    {
        $enum = $capability instanceof ModelCapability ? $capability : ModelCapability::tryFrom($capability);
        if ($enum === null || !$this->has($enum)) {
            return $this;
        }
        $filtered = array_values(array_filter(
            $this->capabilities,
            static fn(ModelCapability $cap): bool => $cap !== $enum,
        ));
        return new self($filtered);
    }

    /**
     * @return list<string>
     */
    public function jsonSerialize(): array
    {
        return $this->toStringList();
    }
}
