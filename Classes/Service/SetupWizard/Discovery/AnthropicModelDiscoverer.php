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
 * Anthropic model discovery: x-api-key + anthropic-version listing, spec
 * enrichment with API display names, recommended-first sort; falls back to a
 * hand-maintained catalog.
 */
final class AnthropicModelDiscoverer extends AbstractModelDiscoverer
{
    /**
     * Discover Anthropic models via API.
     */
    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . self::MODELS_PATH)
                ->withHeader('x-api-key', $apiKey)
                ->withHeader('anthropic-version', '2023-06-01');

            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError('anthropic', $response->getStatusCode());

                return DiscoveryResult::fallback($this->getAnthropicFallbackModels());
            }

            $data = $this->decodeModelListBody('anthropic', $response->getBody()->getContents());
            $modelList = is_array($data) && isset($data['data']) && is_array($data['data'])
                ? $data['data']
                : [];

            $models = [];
            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }

                $modelId = $model['id'] ?? '';
                if (!is_string($modelId)) {
                    continue;
                }

                if ($modelId === '') {
                    continue;
                }

                /** @var array<string, mixed> $model */
                $models[] = $this->enrichAnthropicModel($modelId, $model);
            }

            // Sort: recommended first, then by name
            usort($models, fn(DiscoveredModel $a, DiscoveredModel $b): int => $b->recommended <=> $a->recommended);

            return $models !== [] ? DiscoveryResult::live($models) : DiscoveryResult::fallback($this->getAnthropicFallbackModels());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('anthropic', $e);

            return DiscoveryResult::fallback($this->getAnthropicFallbackModels());
        }
    }

    /**
     * Enrich Anthropic model with known specifications.
     *
     * @param array<string, mixed> $apiData
     */
    private function enrichAnthropicModel(string $modelId, array $apiData): DiscoveredModel
    {
        $specs = [
            'claude-opus-4-5' => [
                'name' => 'Claude Opus 4.5',
                'description' => 'Most intelligent, best for coding, agents, and computer use',
                'costInput' => 500,
                'costOutput' => 2500,
                'recommended' => true,
            ],
            'claude-sonnet-4-5' => [
                'name' => 'Claude Sonnet 4.5',
                'description' => 'Balanced performance and cost',
                'costInput' => 300,
                'costOutput' => 1500,
                'recommended' => true,
            ],
            'claude-haiku-4-5' => [
                'name' => 'Claude Haiku 4.5',
                'description' => 'Fast and cost-effective for simple tasks',
                'costInput' => 100,
                'costOutput' => 500,
                'recommended' => true,
            ],
            'claude-opus-4' => [
                'name' => 'Claude Opus 4',
                'description' => 'Previous generation Opus',
                'costInput' => 1500,
                'costOutput' => 7500,
                'recommended' => false,
            ],
            'claude-sonnet-4' => [
                'name' => 'Claude Sonnet 4',
                'description' => 'Previous generation Sonnet',
                'costInput' => 300,
                'costOutput' => 1500,
                'recommended' => false,
            ],
        ];

        // Match by prefix (API returns dated versions like claude-opus-4-5-20251101)
        $spec = $specs[$modelId] ?? null;
        if ($spec === null) {
            foreach ($specs as $prefix => $s) {
                if (str_starts_with($modelId, $prefix)) {
                    $spec = $s;
                    break;
                }
            }
        }

        $displayName = isset($apiData['display_name']) && is_string($apiData['display_name'])
            ? $apiData['display_name']
            : ($spec['name'] ?? $modelId);

        return new DiscoveredModel(
            modelId: $modelId,
            name: $displayName,
            description: $spec['description'] ?? 'Anthropic model',
            capabilities: ['chat', 'vision', 'tools', 'streaming'],
            contextLength: 200000,
            maxOutputTokens: 32000,
            costInput: $spec['costInput'] ?? 0,
            costOutput: $spec['costOutput'] ?? 0,
            recommended: $spec['recommended'] ?? false,
        );
    }

    /**
     * Get Anthropic fallback models (when API discovery fails).
     *
     * @return array<DiscoveredModel>
     */
    private function getAnthropicFallbackModels(): array
    {
        return [
            new DiscoveredModel(
                modelId: 'claude-opus-4-5-20251101',
                name: 'Claude Opus 4.5',
                description: 'Most intelligent, best for coding, agents, and computer use',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 32000,
                costInput: 500,
                costOutput: 2500,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-sonnet-4-5-20250929',
                name: 'Claude Sonnet 4.5',
                description: 'Balanced performance and cost',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 32000,
                costInput: 300,
                costOutput: 1500,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-haiku-4-5-20251001',
                name: 'Claude Haiku 4.5',
                description: 'Fast and cost-effective for simple tasks',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 16000,
                costInput: 100,
                costOutput: 500,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-opus-4-20250514',
                name: 'Claude Opus 4',
                description: 'Previous generation Opus',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 16000,
                costInput: 1500,
                costOutput: 7500,
                recommended: false,
            ),
            new DiscoveredModel(
                modelId: 'claude-sonnet-4-20250514',
                name: 'Claude Sonnet 4',
                description: 'Previous generation Sonnet',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 16000,
                costInput: 300,
                costOutput: 1500,
                recommended: false,
            ),
        ];
    }
}
