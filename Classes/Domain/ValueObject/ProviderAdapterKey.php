<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Stringable;

/**
 * The name an adapter calls itself: `openai`, `claude`, `ollama` (#893).
 *
 * What {@see \Netresearch\NrLlm\Provider\Contract\ProviderInterface::getIdentifier()}
 * returns, and what {@see \Netresearch\NrLlm\Service\KeyedProviderRegistry} is
 * keyed by.
 *
 * It exists because the OTHER identifier is spelled the same way. A
 * `tx_nrllm_provider` row also has an identifier — `openai-dcbd8f` on any
 * installation set up through the wizard — and it is also returned by a method
 * called `getIdentifier()`, on {@see \Netresearch\NrLlm\Domain\Model\Provider}.
 * Two namespaces behind one method name, both non-empty strings, and handing
 * one where the other belongs produces a plausible "Provider … not found"
 * rather than an error that points at the mistake. That shipped in 0.32.0 and
 * was fixed in 0.33.0 (#873); this is what makes it unrepresentable rather than
 * merely fixed once.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ProviderAdapterKey implements Stringable
{
    /** Normalized: never blank, never padded. */
    public string $value;

    public function __construct(string $value)
    {
        // Trimmed, not merely checked. Storing the padded form would leave
        // `openai ` constructible -- a key that is not blank, so it passes,
        // and does not match `openai`, so the registry answers "Provider …
        // not found". That is the very failure this type exists to make
        // unrepresentable, arriving through the door left open by validating
        // one string and keeping another.
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidArgumentException('A provider adapter key cannot be blank.', 1788300001);
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
