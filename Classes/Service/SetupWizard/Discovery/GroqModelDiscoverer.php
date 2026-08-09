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
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
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
                    // `chat` alone, and it stays that way until Groq reports
                    // more. Their listing returns id, object, created,
                    // owned_by, active, context_window, max_completion_tokens
                    // and public_apps — no capability field of any kind. Most
                    // models here do handle tools, but this seed says what the
                    // API said, and the operator completes the record in the
                    // backend (the field is an editable checkbox list). A
                    // name-pattern guess is what this change removed elsewhere.
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
