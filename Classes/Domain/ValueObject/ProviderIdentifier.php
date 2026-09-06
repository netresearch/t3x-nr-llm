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
 * The identifier of a `tx_nrllm_provider` row: `openai-dcbd8f` (#893).
 *
 * What {@see \Netresearch\NrLlm\Domain\Model\Provider::getIdentifier()} returns,
 * and what {@see \Netresearch\NrLlm\Domain\Repository\ProviderRepository::findOneByIdentifier()}
 * looks a record up by.
 *
 * It is the counterpart of {@see ProviderAdapterKey}, which names the ADAPTER
 * (`openai`) rather than the record. Both are non-empty strings, both are
 * returned by a method called `getIdentifier()`, and handing one where the
 * other belongs used to produce a plausible "Provider … not found" instead of
 * an error pointing at the mistake -- the 0.32.0 bug fixed in 0.33.0 (#873).
 * The wizard's suffix is what keeps the two apart on a real installation, and
 * nothing enforced that a value carrying it never reached the adapter registry.
 * Two types with no common ancestor do.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ProviderIdentifier implements Stringable
{
    /** Normalized: never blank, never padded. */
    public string $value;

    public function __construct(string $value)
    {
        // Trimmed, not merely checked -- see ProviderAdapterKey for why the
        // padded form must not survive construction: `openai-dcbd8f ` is not
        // blank, so it passes, and matches no row, so the repository answers
        // null and the caller reports a provider that does exist as missing.
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidArgumentException('A provider identifier cannot be blank.', 1788300003);
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
