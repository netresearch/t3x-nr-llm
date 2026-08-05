<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Throwable;

/**
 * Mistral model discovery via the shared Bearer listing; falls back to a
 * small static catalog.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class MistralModelDiscoverer extends AbstractModelDiscoverer
{
    /**
     * Discover Mistral models.
     */
    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        try {
            $modelList = $this->fetchBearerModelList('mistral', $endpoint, $apiKey);
            if ($modelList === null) {
                return DiscoveryResult::fallback($this->getMistralFallbackModels());
            }

            $models = [];
            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }

                $modelId = isset($model['id']) && is_string($model['id']) ? $model['id'] : '';
                if ($modelId === '') {
                    continue;
                }

                $models[] = new DiscoveredModel(
                    modelId: $modelId,
                    name: $modelId,
                    description: 'Mistral AI model',
                    capabilities: ['chat', 'tools'],
                    contextLength: 0,
                    maxOutputTokens: 0,
                    costInput: 0,
                    costOutput: 0,
                    recommended: str_contains($modelId, 'large') || str_contains($modelId, 'medium'),
                );
            }

            return $models !== [] ? DiscoveryResult::live($models) : DiscoveryResult::fallback($this->getMistralFallbackModels());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('mistral', $e);

            return DiscoveryResult::fallback($this->getMistralFallbackModels());
        }
    }

    /**
     * Get Mistral fallback models.
     *
     * @return array<DiscoveredModel>
     */
    private function getMistralFallbackModels(): array
    {
        return [
            new DiscoveredModel(
                modelId: 'mistral-large-latest',
                name: 'Mistral Large',
                description: 'Flagship model for complex tasks',
                capabilities: ['chat', 'tools', 'streaming'],
                contextLength: 128000,
                maxOutputTokens: 8192,
                costInput: 200,
                costOutput: 600,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'mistral-medium-latest',
                name: 'Mistral Medium',
                description: 'Balanced performance',
                capabilities: ['chat', 'tools', 'streaming'],
                contextLength: 32000,
                maxOutputTokens: 8192,
                costInput: 100,
                costOutput: 300,
                recommended: true,
            ),
        ];
    }
}
