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
 * The name of a backend-managed LLM configuration: `blog-summarizer` (#893).
 *
 * What {@see \Netresearch\NrLlm\Domain\Model\LlmConfiguration::getIdentifier()}
 * returns, what a `tx_nrllm_configuration` row is looked up by, and what an
 * {@see \Netresearch\NrLlm\Domain\Model\AiSession} records when it binds itself
 * to one configuration for its whole life (ADR-188).
 *
 * It is the third string in this extension called an "identifier" and returned
 * by a method called `getIdentifier()` -- after the adapter key and the
 * provider row, which {@see ProviderAdapterKey} already separates. A
 * configuration identifier names a whole call setup: provider, model or model
 * criteria, budget and guardrails. Handing one where a provider identifier
 * belongs, or the reverse, produces a lookup that finds nothing and reports
 * "not found" for a record that exists under the other name.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ConfigurationIdentifier implements Stringable
{
    /** Normalized: never blank, never padded. */
    public string $value;

    public function __construct(string $value)
    {
        // Trimmed, not merely checked -- for the reason spelled out on
        // {@see ProviderAdapterKey}: validating one string and storing another
        // leaves `blog-summarizer ` constructible, which is not blank and does
        // not match `blog-summarizer`, so the repository answers "not found"
        // for a configuration that is right there.
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidArgumentException('A configuration identifier cannot be blank.', 1788300002);
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
