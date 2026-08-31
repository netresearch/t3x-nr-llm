<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenRouter;

use Closure;
use Netresearch\NrLlm\Provider\ResponseParserTrait;

/**
 * OpenRouter's model routing, extracted from the provider (ADR-125).
 *
 * "OpenRouter is a router" is the product's premise, and this is the one
 * cluster in the adapter that is pure decision logic: no HTTP, no PSR-7, no
 * response objects. It picks a model id from the live catalogue by strategy —
 * cheapest by average token price, fastest or balanced by id keyword, vision
 * by capability flag — and falls back to the configured default whenever the
 * catalogue or the candidates run dry.
 *
 * Deliberately stateless. The routing strategy stays a property of the
 * provider (its `configure()` owns and validates it) and arrives per call, and
 * the catalogue arrives as a closure so a call that names its model explicitly
 * still performs no network fetch — exactly the short-circuit the inline code
 * had. Bodies were moved verbatim; the keyword heuristics and the hardcoded
 * vision preference list are documented behaviour, not accidents of the move.
 *
 * Constructed inline by the provider, not injected: the adapter constructor
 * signature is shared by all seven providers and fixed by the compiler pass,
 * and this collaborator is an implementation detail of one of them.
 */
final readonly class ModelRouter
{
    use ResponseParserTrait;

    /**
     * Select model based on routing strategy.
     *
     * @param array<string, mixed>                                                                                                                                   $options
     * @param Closure(): array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $fetchModels
     */
    public function select(array $options, string $routingStrategy, string $defaultModel, Closure $fetchModels): string
    {
        // Explicit model specified in options
        $model = $this->getString($options, 'model');
        if ($model !== '') {
            return $model;
        }

        // Non-explicit strategies attempt smart routing over the available models.
        if ($routingStrategy !== 'explicit') {
            $models = $fetchModels();

            // Filter models by requirements
            $candidates = $models === []
                ? []
                : $this->filterModelsByRequirements($models, $options);

            if ($candidates !== []) {
                return match ($routingStrategy) {
                    'cost_optimized' => $this->selectCheapestModel($candidates, $defaultModel),
                    'performance' => $this->selectFastestModel($candidates, $defaultModel),
                    'balanced' => $this->selectBalancedModel($candidates, $defaultModel),
                    default => $defaultModel,
                };
            }
        }

        // Explicit strategy, no models available, or no matching candidates: use default model
        return $defaultModel;
    }

    /**
     * Select vision-capable model.
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $models
     */
    public function selectVisionModel(array $models, string $defaultModel): string
    {
        $visionModels = [
            'anthropic/claude-sonnet-4-5',
            'anthropic/claude-opus-4-5',
            'openai/gpt-5.2',
            'openai/gpt-5.2-pro',
            'google/gemini-3-flash',
        ];

        // Check if default model supports vision
        if (isset($models[$defaultModel]['capabilities']['vision'])
            && $models[$defaultModel]['capabilities']['vision']) {
            return $defaultModel;
        }

        // Find first available vision model
        foreach ($visionModels as $model) {
            if (isset($models[$model]) || $models === []) {
                return $model;
            }
        }

        return 'openai/gpt-5.2'; // Fallback
    }

    /**
     * Filter models by requirements from options.
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $models
     * @param array<string, mixed>                                                                                                                        $options
     *
     * @return array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}>
     */
    private function filterModelsByRequirements(array $models, array $options): array
    {
        $filtered = $models;

        // Context length requirement
        $minContext = $this->getInt($options, 'min_context');
        if ($minContext > 0) {
            $filtered = array_filter(
                $filtered,
                static fn(array $model): bool => $model['context_length'] >= $minContext,
            );
        }

        // Vision capability
        if ($this->getBool($options, 'vision_required')) {
            $filtered = array_filter(
                $filtered,
                static fn(array $model): bool => $model['capabilities']['vision'] ?? false,
            );
        }

        // Function calling
        if ($this->getBool($options, 'function_calling')) {
            return array_filter(
                $filtered,
                static fn(array $model): bool => $model['capabilities']['function_calling'] ?? false,
            );
        }

        return $filtered;
    }

    /**
     * Select cheapest model from candidates.
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $candidates
     */
    private function selectCheapestModel(array $candidates, string $defaultModel): string
    {
        $cheapest = null;
        $lowestCost = PHP_FLOAT_MAX;

        foreach ($candidates as $id => $model) {
            $avgCost = (($model['pricing']['prompt'] ?? 0) + ($model['pricing']['completion'] ?? 0)) / 2;
            if ($avgCost < $lowestCost) {
                $lowestCost = $avgCost;
                $cheapest = $id;
            }
        }

        return $cheapest ?? $defaultModel;
    }

    /**
     * Select fastest model (heuristic: flash/haiku/turbo models).
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $candidates
     */
    private function selectFastestModel(array $candidates, string $defaultModel): string
    {
        $fastKeywords = ['flash', 'haiku', 'turbo', 'instant', 'mini'];

        foreach ($candidates as $id => $model) {
            foreach ($fastKeywords as $keyword) {
                if (stripos($id, $keyword) !== false) {
                    return $id;
                }
            }
        }

        return $defaultModel;
    }

    /**
     * Select balanced model (mid-tier quality and speed).
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $candidates
     */
    private function selectBalancedModel(array $candidates, string $defaultModel): string
    {
        $balancedKeywords = ['sonnet', 'medium', '3.5', 'pro'];

        foreach ($candidates as $id => $model) {
            foreach ($balancedKeywords as $keyword) {
                if (stripos($id, $keyword) !== false) {
                    return $id;
                }
            }
        }

        return $defaultModel;
    }
}
