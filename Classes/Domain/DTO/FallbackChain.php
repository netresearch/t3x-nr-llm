<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\DTO;

use JsonSerializable;

/**
 * Ordered list of LlmConfiguration identifiers to try when the primary
 * configuration fails with a retryable error.
 *
 * Fallback is shallow: a fallback configuration's own chain is ignored
 * to prevent recursion and cycles. Identifiers are normalised (trimmed
 * and lowercased) to match how `tx_nrllm_configuration.identifier`
 * stores them (TCA `eval=trim,alphanum_x,lower,unique`); withLink(),
 * without() and contains() all apply the same normalisation so a
 * manually-edited JSON payload with stray whitespace or capitals still
 * resolves to the right row. Normalised duplicates are dropped on entry
 * via fromArray()/fromJson() and silently skipped by withLink().
 *
 * The constructor itself does NOT normalise — it trusts already-sanitised
 * input (see the sanitize() docblock).
 */
final readonly class FallbackChain implements JsonSerializable
{
    /**
     * @param list<string> $configurationIdentifiers Ordered list of LlmConfiguration identifiers (should be pre-normalised)
     */
    public function __construct(
        public array $configurationIdentifiers = [],
    ) {}

    /**
     * @param array{configurationIdentifiers?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        $identifiers = $data['configurationIdentifiers'] ?? [];
        if (!is_array($identifiers)) {
            return new self();
        }
        return new self(self::sanitize($identifiers));
    }

    public static function fromJson(string $json): self
    {
        if ($json === '') {
            return new self();
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new self();
        }
        /** @var array{configurationIdentifiers?: mixed} $data */
        return self::fromArray($data);
    }

    /**
     * @return array{configurationIdentifiers: list<string>}
     */
    public function toArray(): array
    {
        return [
            'configurationIdentifiers' => $this->configurationIdentifiers,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{configurationIdentifiers: list<string>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isEmpty(): bool
    {
        return $this->configurationIdentifiers === [];
    }

    public function count(): int
    {
        return count($this->configurationIdentifiers);
    }

    public function contains(string $identifier): bool
    {
        return in_array(self::normalise($identifier), $this->configurationIdentifiers, true);
    }

    /**
     * Return a new chain with the given identifier appended, deduplicated.
     * Empty / whitespace-only identifiers are silently ignored.
     */
    public function withLink(string $identifier): self
    {
        $normalised = self::normalise($identifier);
        if ($normalised === '' || $this->contains($normalised)) {
            return $this;
        }
        return new self([...$this->configurationIdentifiers, $normalised]);
    }

    /**
     * Return a new chain without the given identifier.
     * Useful for excluding the primary configuration before walking fallbacks.
     */
    public function without(string $identifier): self
    {
        $normalised = self::normalise($identifier);
        if ($normalised === '' || !$this->contains($normalised)) {
            return $this;
        }
        $filtered = array_values(array_filter(
            $this->configurationIdentifiers,
            static fn(string $link): bool => $link !== $normalised,
        ));
        return new self($filtered);
    }

    /**
     * Normalise input: drop non-strings, drop empty strings, trim, lowercase,
     * drop duplicates, reindex as list. Matches the sanitisation TCA already
     * applies to `tx_nrllm_configuration.identifier` so round-trips through
     * hand-written JSON do not accidentally miss a row.
     *
     * @param array<mixed> $identifiers
     *
     * @return list<string>
     */
    private static function sanitize(array $identifiers): array
    {
        $seen = [];
        $out = [];
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier)) {
                continue;
            }
            $normalised = self::normalise($identifier);
            if ($normalised === '' || isset($seen[$normalised])) {
                continue;
            }
            $seen[$normalised] = true;
            $out[] = $normalised;
        }
        return $out;
    }

    private static function normalise(string $identifier): string
    {
        return strtolower(trim($identifier));
    }
}
