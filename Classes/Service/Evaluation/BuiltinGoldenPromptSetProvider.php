<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;

/**
 * The example golden set nr_llm ships so `nrllm:eval:run` has a runnable
 * target out of the box and consuming extensions have a concrete pattern to
 * copy (ADR-060).
 *
 * The set is intentionally small and provider-agnostic: three factual
 * prompts graded deterministically (contains / regex / exact). It exercises
 * the machinery, not a specific model's knowledge — nothing here runs unless
 * the eval command is invoked.
 */
final readonly class BuiltinGoldenPromptSetProvider implements GoldenPromptSetProviderInterface
{
    public const SET_IDENTIFIER = 'nr_llm.smoke';

    public function getGoldenPromptSets(): array
    {
        return [
            new GoldenPromptSet(
                identifier: self::SET_IDENTIFIER,
                name: 'nr_llm smoke evaluation',
                description: 'A minimal provider-agnostic set that checks the model answers three simple factual prompts. Ships as the example pattern for consumer golden sets.',
                prompts: [
                    new GoldenPrompt(
                        id: 'capital-of-france',
                        prompt: 'What is the capital of France? Answer with the city name only.',
                        assertions: [Assertion::contains('Paris')],
                        systemPrompt: 'You are a concise factual assistant. Answer in as few words as possible.',
                        reference: 'Paris',
                    ),
                    new GoldenPrompt(
                        id: 'two-plus-two',
                        prompt: 'Compute 2 + 2 and reply with the digit only.',
                        assertions: [Assertion::regex('/\b4\b/')],
                        reference: '4',
                    ),
                    new GoldenPrompt(
                        id: 'echo-token',
                        prompt: 'Reply with exactly the single word: ACKNOWLEDGED',
                        assertions: [Assertion::contains('ACKNOWLEDGED')],
                        reference: 'ACKNOWLEDGED',
                    ),
                ],
                criteria: new ModelSelectionCriteria(capabilities: [ModelCapability::CHAT->value]),
            ),
        ];
    }
}
