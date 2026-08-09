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
 * Gemini model discovery: x-goog-api-key listing with the models/ prefix
 * stripped, relevance filter, spec enrichment with API token limits, API
 * order preserved (deliberately unsorted); falls back to a static catalog.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class GeminiModelDiscoverer extends AbstractModelDiscoverer
{
    /**
     * Discover Gemini models.
     */
    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        try {
            $url = $endpoint . '/models';
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('x-goog-api-key', $apiKey);
            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError('gemini', $response->getStatusCode());

                return DiscoveryResult::fallback($this->getGeminiFallbackModels());
            }

            $data = $this->decodeModelListBody('gemini', $response->getBody()->getContents());
            $modelList = is_array($data) && isset($data['models']) && is_array($data['models'])
                ? $data['models']
                : [];

            $models = [];
            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }

                $modelName = $model['name'] ?? '';
                if (!is_string($modelName)) {
                    continue;
                }

                $modelId = str_replace('models/', '', $modelName);
                if ($modelId === '') {
                    continue;
                }

                if (!$this->isRelevantGeminiModel($modelId)) {
                    continue;
                }

                /** @var array<string, mixed> $model */
                $models[] = $this->enrichGeminiModel($modelId, $model);
            }

            return $models !== [] ? DiscoveryResult::live($models) : DiscoveryResult::fallback($this->getGeminiFallbackModels());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('gemini', $e);

            return DiscoveryResult::fallback($this->getGeminiFallbackModels());
        }
    }

    /**
     * Check if Gemini model is relevant.
     */
    private function isRelevantGeminiModel(string $modelId): bool
    {
        // Include all gemini models, exclude deprecated/embedding-only
        if (!str_starts_with($modelId, 'gemini-')) {
            return false;
        }

        // Exclude embedding models (not usable for chat)
        // and very old models (gemini-1.0, gemini-pro)
        return !(str_contains($modelId, 'embedding') || str_starts_with($modelId, 'gemini-1.0') || str_starts_with($modelId, 'gemini-pro'));
    }

    /**
     * What the listing itself says about a model the curated table does not
     * know — a Gemini release newer than this extension.
     *
     * `supportedGenerationMethods` is the only capability-bearing field the
     * listing carries. It substantiates chat, streaming and embeddings, and
     * says nothing about vision or tools; the previous fallback claimed
     * `vision` for every unknown model, which is how a text-only release
     * ended up advertising image input.
     *
     * @param array<string, mixed> $apiData
     *
     * @return list<string>
     */
    private function capabilitiesFrom(array $apiData): array
    {
        $methods = isset($apiData['supportedGenerationMethods']) && is_array($apiData['supportedGenerationMethods'])
            ? $apiData['supportedGenerationMethods']
            : [];

        $capabilities = [];
        if (in_array('generateContent', $methods, true)) {
            $capabilities[] = 'chat';
        }

        if (in_array('streamGenerateContent', $methods, true)) {
            $capabilities[] = 'streaming';
        }

        if (in_array('embedContent', $methods, true)) {
            $capabilities[] = 'embeddings';
        }

        return $capabilities === [] ? ['chat'] : $capabilities;
    }

    /**
     * Enrich Gemini model with specifications.
     *
     * @param array<string, mixed> $apiData
     */
    private function enrichGeminiModel(string $modelId, array $apiData): DiscoveredModel
    {
        // December 2025 Gemini specifications
        $specs = [
            'gemini-3-flash' => [
                'name' => 'Gemini 3 Flash',
                'description' => 'Frontier intelligence built for speed',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'costInput' => 50, // $0.50 per 1M
                'costOutput' => 300, // $3 per 1M
                'recommended' => true,
            ],
            'gemini-3-pro' => [
                'name' => 'Gemini 3 Pro',
                'description' => 'Advanced reasoning for agentic workflows',
                // No `reasoning`: it is not in ModelCapability, so CapabilitySet
                // drops it and the TCA checkbox list cannot even show it.
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'costInput' => 125,
                'costOutput' => 500,
                'recommended' => true,
            ],
            'gemini-2.5-flash' => [
                'name' => 'Gemini 2.5 Flash',
                'description' => 'Previous generation fast model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'costInput' => 35,
                'costOutput' => 150,
                'recommended' => false,
            ],
            'gemini-2.0-flash' => [
                'name' => 'Gemini 2.0 Flash',
                'description' => 'Cost-effective general purpose',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'costInput' => 10,
                'costOutput' => 40,
                'recommended' => false,
            ],
        ];

        $spec = $specs[$modelId] ?? null;

        // Extract values with proper type casting
        $displayName = isset($apiData['displayName']) && is_string($apiData['displayName'])
            ? $apiData['displayName']
            : $modelId;
        $apiDescription = isset($apiData['description']) && is_string($apiData['description'])
            ? $apiData['description']
            : 'Gemini model';
        $inputTokenLimit = isset($apiData['inputTokenLimit']) && is_int($apiData['inputTokenLimit'])
            ? $apiData['inputTokenLimit']
            : 1000000;
        $outputTokenLimit = isset($apiData['outputTokenLimit']) && is_int($apiData['outputTokenLimit'])
            ? $apiData['outputTokenLimit']
            : 8192;

        return new DiscoveredModel(
            modelId: $modelId,
            name: $spec['name'] ?? $displayName,
            description: $spec['description'] ?? $apiDescription,
            capabilities: $spec['capabilities'] ?? $this->capabilitiesFrom($apiData),
            contextLength: $inputTokenLimit,
            maxOutputTokens: $outputTokenLimit,
            costInput: $spec['costInput'] ?? 0,
            costOutput: $spec['costOutput'] ?? 0,
            recommended: $spec['recommended'] ?? false,
        );
    }

    /**
     * Get Gemini fallback models.
     *
     * @return array<DiscoveredModel>
     */
    private function getGeminiFallbackModels(): array
    {
        return [
            new DiscoveredModel(
                modelId: 'gemini-3-flash',
                name: 'Gemini 3 Flash',
                description: 'Frontier intelligence built for speed',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 1000000,
                maxOutputTokens: 65536,
                costInput: 50,
                costOutput: 300,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'gemini-3-pro',
                name: 'Gemini 3 Pro',
                description: 'Advanced reasoning for agentic workflows',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 1000000,
                maxOutputTokens: 65536,
                costInput: 125,
                costOutput: 500,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'gemini-2.5-flash',
                name: 'Gemini 2.5 Flash',
                description: 'Previous generation fast model',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 1000000,
                maxOutputTokens: 8192,
                costInput: 35,
                costOutput: 150,
                recommended: false,
            ),
        ];
    }
}
