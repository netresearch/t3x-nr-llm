<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\DTO;

use Countable;
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
 * Defensive parsing: `fromCsv()` and `fromArray()` drop unknown enum
 * tokens silently (typos, removed-but-still-stored capabilities from a
 * future `OLD_NAME → NEW_NAME` rename, stray whitespace via the trim
 * pass). Token matching is case-SENSITIVE — `ModelCapability::tryFrom()`
 * does not lowercase, and `chat` ≠ `CHAT` to it. Callers that need to
 * know about invalid tokens should validate against
 * `ModelCapability::isValid()` before construction.
 *
 * Constructor contract: like `Domain/DTO/FallbackChain`, the public
 * constructor TRUSTS its `$capabilities` argument — pass already-typed
 * `ModelCapability` enums and accept that duplicates are the caller's
 * problem. The factories `fromCsv()` / `fromArray()` are the safe
 * entry points for arbitrary input; they dedupe and skip unknown
 * tokens. Tests can construct directly when they need a known shape.
 */
final readonly class CapabilitySet implements Countable, JsonSerializable
{
    /**
     * @param list<ModelCapability> $capabilities Trusted: already-typed enums.
     *                                            Use `fromArray()` / `fromCsv()`
     *                                            for arbitrary / untrusted input.
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
            $enum = self::coerceToEnum($token);
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
     * them) do not need an immediate change. Strings are normalised
     * via `coerceToEnum()` so `' chat'` resolves the same as `'chat'`.
     */
    public function has(ModelCapability|string $capability): bool
    {
        $needle = self::coerceToEnum($capability);
        if ($needle === null) {
            return false;
        }
        // PHP enums are singletons, so strict equality is enough — and
        // `in_array(..., true)` short-circuits on first match.
        return in_array($needle, $this->capabilities, true);
    }

    /**
     * Return a new set with the given capability added (idempotent).
     * Strings are normalised via `coerceToEnum()`; unknown strings
     * yield an unchanged set rather than a silent corruption.
     */
    public function with(ModelCapability|string $capability): self
    {
        $enum = self::coerceToEnum($capability);
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
        $enum = self::coerceToEnum($capability);
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

    /**
     * Single normalisation point shared by `fromArray()`, `has()`,
     * `with()`, `without()`. Ensures that `' chat'`, `'chat'`, and
     * `ModelCapability::CHAT` all resolve to the same enum case.
     * Returns null for unknown / non-string / non-enum input.
     *
     * Note: case-SENSITIVE matching only — `ModelCapability::tryFrom()`
     * does not lowercase, and the persisted CSV is always lowercase
     * (TCA `eval=trim,lower`), so `'CHAT'` will be dropped as unknown.
     * Callers that want case-insensitive resolution must lowercase
     * before passing in.
     */
    private static function coerceToEnum(mixed $token): ?ModelCapability
    {
        if ($token instanceof ModelCapability) {
            return $token;
        }
        if (!is_string($token)) {
            return null;
        }
        return ModelCapability::tryFrom(trim($token));
    }
}
