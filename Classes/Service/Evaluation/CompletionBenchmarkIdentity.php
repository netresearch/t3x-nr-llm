<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * The declared completion experiment, independent of the serving generator (ADR-271).
 */
final class CompletionBenchmarkIdentity
{
    /**
     * @param array<string, scalar> $protocol
     */
    public static function forSet(
        GoldenPromptSet $set,
        ChatOptions $options,
        array $protocol,
    ): string {
        $settings = $options->toArray();
        unset($settings['provider'], $settings['model'], $settings['system_prompt']);
        $prompts = [];
        foreach ($set->prompts as $prompt) {
            $prompts[] = [
                $prompt->id,
                $prompt->prompt,
                $prompt->systemPrompt ?? $options->getSystemPrompt(),
                array_map(
                    static fn (
                        Assertion $assertion,
                    ): array => [$assertion->type->value, $assertion->value],
                    $prompt->assertions,
                ),
                $prompt->reference,
            ];
        }

        // Serialize preserves arbitrary content bytes, scalar types and list order.
        // Nothing is persisted except the digest; no credentials are part of ChatOptions.
        return 'v1:' . hash(
            'sha256',
            serialize(
                [
                    'completion-benchmark-v1',
                    $prompts,
                    $settings,
                    $options->getSite(),
                    $protocol,
                ],
            ),
        );
    }

    public static function isKnown(string $fingerprint): bool
    {
        return preg_match('/^v1:[a-f0-9]{64}$/D', $fingerprint) === 1;
    }

    /**
     * @param list<string> $effectiveRequests
     */
    public static function forRun(
        string $declaredFingerprint,
        array $effectiveRequests,
    ): string {
        if ($effectiveRequests === [] || !self::isKnown($declaredFingerprint)) {
            return '';
        }

        foreach ($effectiveRequests as $request) {
            if (!self::isKnown($request)) {
                return '';
            }
        }

        return 'v1:' . hash(
            'sha256',
            serialize(
                ['completion-run-v1', $declaredFingerprint, $effectiveRequests],
            ),
        );
    }
}
