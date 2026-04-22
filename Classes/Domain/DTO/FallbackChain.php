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
 * to prevent recursion and cycles. Duplicates (including the primary's
 * own identifier) are silently rejected on construction and on withLink().
 */
final readonly class FallbackChain implements JsonSerializable
{
    /**
     * @param list<string> $configurationIdentifiers Ordered list of LlmConfiguration identifiers
     */
    public function __construct(
        public array $configurationIdentifiers = [],
    ) {}

    /**
     * @param array{configurationIdentifiers?: list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        $identifiers = $data['configurationIdentifiers'] ?? [];
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
        /** @var array{configurationIdentifiers?: list<string>} $data */
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
        return in_array($identifier, $this->configurationIdentifiers, true);
    }

    /**
     * Return a new chain with the given identifier appended, deduplicated.
     * Empty identifiers are silently ignored.
     */
    public function withLink(string $identifier): self
    {
        if ($identifier === '' || $this->contains($identifier)) {
            return $this;
        }
        return new self([...$this->configurationIdentifiers, $identifier]);
    }

    /**
     * Return a new chain without the given identifier.
     * Useful for excluding the primary configuration before walking fallbacks.
     */
    public function without(string $identifier): self
    {
        if ($identifier === '' || !$this->contains($identifier)) {
            return $this;
        }
        $filtered = array_values(array_filter(
            $this->configurationIdentifiers,
            static fn(string $link): bool => $link !== $identifier,
        ));
        return new self($filtered);
    }

    /**
     * Normalize input: drop empty strings and duplicates, reindex as list.
     *
     * @param list<string> $identifiers
     *
     * @return list<string>
     */
    private static function sanitize(array $identifiers): array
    {
        $seen = [];
        $out = [];
        foreach ($identifiers as $identifier) {
            if ($identifier === '' || isset($seen[$identifier])) {
                continue;
            }
            $seen[$identifier] = true;
            $out[] = $identifier;
        }
        return $out;
    }
}
