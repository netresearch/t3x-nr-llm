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
 * OpenRouter model discovery via the shared Bearer listing; carries the
 * published per-token prices into the catalog. A failure yields an empty
 * list — the router's catalog is too large and volatile to can.
 */
final class OpenRouterModelDiscoverer extends AbstractModelDiscoverer
{
    /**
     * Discover OpenRouter models.
     */
    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        try {
            $modelList = $this->fetchBearerModelList('openrouter', $endpoint, $apiKey);
            if ($modelList === null) {
                return DiscoveryResult::live([]);
            }

            $models = [];
            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }

                $discovered = $this->mapOpenRouterModel($model);
                if ($discovered instanceof DiscoveredModel) {
                    $models[] = $discovered;
                }
            }

            return DiscoveryResult::live($models);
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('openrouter', $e);

            return DiscoveryResult::live([]);
        }
    }

    /**
     * Map a single OpenRouter API model entry to a DiscoveredModel.
     *
     * @param array<int|string, mixed> $model
     */
    private function mapOpenRouterModel(array $model): ?DiscoveredModel
    {
        $modelId = isset($model['id']) && is_string($model['id']) ? $model['id'] : '';
        if ($modelId === '') {
            return null;
        }

        $pricing = isset($model['pricing']) && is_array($model['pricing']) ? $model['pricing'] : [];
        $modelName = isset($model['name']) && is_string($model['name']) ? $model['name'] : $modelId;
        $modelDescription = isset($model['description']) && is_string($model['description'])
            ? $model['description']
            : 'OpenRouter model';
        $contextLength = isset($model['context_length']) && is_numeric($model['context_length'])
            ? (int)$model['context_length']
            : 0;
        $promptCost = isset($pricing['prompt']) && is_numeric($pricing['prompt'])
            ? (float)$pricing['prompt']
            : 0.0;
        $completionCost = isset($pricing['completion']) && is_numeric($pricing['completion'])
            ? (float)$pricing['completion']
            : 0.0;

        return new DiscoveredModel(
            modelId: $modelId,
            name: $modelName,
            description: $modelDescription,
            capabilities: ['chat'],
            contextLength: $contextLength,
            maxOutputTokens: 0,
            costInput: (int)($promptCost * 100000000),
            costOutput: (int)($completionCost * 100000000),
            recommended: false,
        );
    }
}
