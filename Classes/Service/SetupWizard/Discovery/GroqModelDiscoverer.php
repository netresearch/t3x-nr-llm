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
 * Groq model discovery via the shared Bearer listing, carrying the API's
 * context_window. A failure yields an empty list.
 */
final class GroqModelDiscoverer extends AbstractModelDiscoverer
{
    /**
     * Discover Groq models.
     */
    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        try {
            $modelList = $this->fetchBearerModelList('groq', $endpoint, $apiKey);
            if ($modelList === null) {
                return DiscoveryResult::live([]);
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

                $contextWindow = isset($model['context_window']) && is_numeric($model['context_window'])
                    ? (int)$model['context_window']
                    : 0;

                $models[] = new DiscoveredModel(
                    modelId: $modelId,
                    name: $modelId,
                    description: 'Groq-accelerated model',
                    capabilities: ['chat'],
                    contextLength: $contextWindow,
                    maxOutputTokens: 0,
                    costInput: 0,
                    costOutput: 0,
                    recommended: true,
                );
            }

            return DiscoveryResult::live($models);
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('groq', $e);

            return DiscoveryResult::live([]);
        }
    }
}
