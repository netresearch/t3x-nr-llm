<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;

/**
 * What one entry of OpenRouter's `GET /api/v1/models` states about reasoning
 * (ADR-274). Shared by the OpenRouter discoverer and the opt-in third-party
 * catalogue lookup, so both read the list the same way.
 *
 * Measured on 2026-11-09 over all 461 entries: a reasoning model carries a
 * `reasoning` object and lists `reasoning` in `supported_parameters`; a model
 * that does not reason (`openai/gpt-4.1-mini`) has neither — the key is
 * absent, never null. A router entry (`openrouter/auto`) has no object but
 * lists the parameter, and says nothing about one model.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class OpenRouterReasoning
{
    private const PARAMETER = 'reasoning';

    /**
     * True for a `reasoning` object; false where the entry has no object and
     * its `supported_parameters` list does not name `reasoning`; null where
     * it states neither — no parameter list, or a parameter without an
     * object. Null records nothing, so "not stated" never becomes "no".
     *
     * @param array<int|string, mixed> $entry
     */
    public static function statedBy(array $entry): ?bool
    {
        $reasoning = $entry[self::PARAMETER] ?? null;
        if (is_array($reasoning)) {
            return true;
        }

        $parameters = $entry['supported_parameters'] ?? null;
        if (!is_array($parameters) || in_array(self::PARAMETER, $parameters, true)) {
            return null;
        }

        return false;
    }

    /**
     * The `reasoning.supported_efforts` list as efforts this extension knows,
     * lowest first. OpenRouter lists them highest first; an unknown value is
     * dropped rather than guessed.
     *
     * @param array<int|string, mixed> $entry
     *
     * @return list<ReasoningEffort>
     */
    public static function effortsStatedBy(array $entry): array
    {
        $reasoning = $entry[self::PARAMETER] ?? null;
        $stated    = is_array($reasoning) && isset($reasoning['supported_efforts']) && is_array($reasoning['supported_efforts'])
            ? $reasoning['supported_efforts']
            : [];

        $efforts = [];
        foreach ($stated as $value) {
            $effort = is_string($value) ? ReasoningEffort::tryFrom(strtolower($value)) : null;
            if ($effort instanceof ReasoningEffort) {
                $efforts[$effort->rank()] = $effort;
            }
        }

        ksort($efforts);

        return array_values($efforts);
    }
}
