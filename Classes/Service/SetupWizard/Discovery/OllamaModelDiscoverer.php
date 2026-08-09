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
 * Ollama model discovery: unauthenticated /api/tags listing with per-model
 * /api/show enrichment capped per run; a failure yields an empty list, never
 * a canned catalog — a local daemon that is down has no models.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class OllamaModelDiscoverer extends AbstractModelDiscoverer
{
    /**
     * Cap on Ollama `/api/show` enrichment calls per discovery run. Each is a
     * blocking round-trip; a host with dozens of pulled models would otherwise
     * fire that many sequentially inside the wizard request and risk exceeding
     * its timeout. Models past the cap are still listed, with default metadata.
     */
    private const MAX_OLLAMA_DETAIL_LOOKUPS = 20;

    /** @var array{description: string, capabilities: array<string>, contextLength: int, maxOutputTokens: int} */
    private const OLLAMA_DEFAULT_DETAILS = [
        'description' => 'Local Ollama model',
        'capabilities' => ['chat'],
        'contextLength' => 0,
        'maxOutputTokens' => 0,
    ];

    /**
     * Ollama's base URL is a bare host (OllamaProvider prefixes every request path with
     * "api/"); strip a trailing "/api" (a legacy default or user-entered value) so
     * appended discovery paths do not become "/api/api/...".
     */
    public static function baseUrl(string $endpoint): string
    {
        return (string)preg_replace('#/api/*$#', '', rtrim($endpoint, '/'));
    }

    /**
     * Discover Ollama models via API.
     */
    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        try {
            $endpoint = self::baseUrl($endpoint);
            $request = $this->requestFactory->createRequest('GET', $endpoint . '/api/tags');
            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError('ollama', $response->getStatusCode());

                return DiscoveryResult::live([]);
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];

            $modelList = is_array($data) && isset($data['models']) && is_array($data['models'])
                ? $data['models']
                : [];

            $detailLookups = 0;
            $detailsTruncated = false;
            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }

                $modelId = isset($model['name']) && is_string($model['name']) ? $model['name'] : '';
                if ($modelId === '') {
                    continue;
                }

                // Enrich context length / capabilities via one /api/show call
                // per model — but cap the number of these blocking round-trips
                // so a host with many models cannot stall the wizard past its
                // timeout. Models beyond the cap are still listed, with default
                // metadata.
                if ($detailLookups < self::MAX_OLLAMA_DETAIL_LOOKUPS) {
                    $modelDetails = $this->getOllamaModelDetails($endpoint, $modelId);
                    ++$detailLookups;
                } else {
                    $modelDetails = self::OLLAMA_DEFAULT_DETAILS;
                    $detailsTruncated = true;
                }

                $models[] = new DiscoveredModel(
                    modelId: $modelId,
                    name: $modelId,
                    description: $modelDetails['description'],
                    capabilities: $modelDetails['capabilities'],
                    contextLength: $modelDetails['contextLength'],
                    maxOutputTokens: $modelDetails['maxOutputTokens'],
                    costInput: 0,
                    costOutput: 0,
                    recommended: true,
                );
            }

            if ($detailsTruncated) {
                $this->logger->info(
                    'Ollama discovery exceeded the per-run /api/show detail-lookup cap; remaining models are listed with default metadata.',
                    ['cap' => self::MAX_OLLAMA_DETAIL_LOOKUPS, 'discovered' => count($models)],
                );
            }

            return DiscoveryResult::live($models);
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('ollama', $e);

            return DiscoveryResult::live([]);
        }
    }

    /**
     * Get detailed model info from Ollama's /api/show endpoint.
     *
     * @return array{description: string, capabilities: array<string>, contextLength: int, maxOutputTokens: int}
     */
    private function getOllamaModelDetails(string $endpoint, string $modelId): array
    {
        $defaults = self::OLLAMA_DEFAULT_DETAILS;

        try {
            // Create body with model name
            $body = json_encode(['name' => $modelId], JSON_THROW_ON_ERROR);
            $stream = $this->streamFactory->createStream($body);

            $request = $this->requestFactory->createRequest('POST', self::baseUrl($endpoint) . '/api/show')
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream);

            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            $data = $response->getStatusCode() === 200
                ? json_decode($response->getBody()->getContents(), true)
                : null;
            if (!is_array($data)) {
                return $defaults;
            }

            $contextLength = $this->extractOllamaContextLength($data);
            $modelIdLower = strtolower($modelId);
            $capabilities = $this->extractOllamaCapabilities($data);

            // Ollama doesn't expose max output tokens, so derive sensible defaults
            // Most models can output up to 1/4 of context, with reasonable caps
            $maxOutputTokens = $this->estimateOllamaMaxOutput($modelIdLower, $contextLength);

            return [
                'description' => 'Local Ollama model',
                'capabilities' => $capabilities,
                'contextLength' => $contextLength,
                'maxOutputTokens' => $maxOutputTokens,
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    /**
     * Extract the context length from an Ollama `/api/show` response.
     *
     * Context length is reported either in `model_info` (newer Ollama
     * versions) or in the `parameters` string (e.g. "num_ctx 32768").
     *
     * @param array<int|string, mixed> $data
     */
    private function extractOllamaContextLength(array $data): int
    {
        // Try to get from model_info (newer Ollama versions)
        $modelInfo = isset($data['model_info']) && is_array($data['model_info'])
            ? $data['model_info']
            : [];
        foreach ($modelInfo as $key => $value) {
            if (str_contains(strtolower((string)$key), 'context') && is_numeric($value)) {
                return (int)$value;
            }
        }

        // Try to parse from parameters string (e.g., "num_ctx 32768")
        $parameters = isset($data['parameters']) && is_string($data['parameters'])
            ? $data['parameters']
            : '';
        if ($parameters !== '' && preg_match('/num_ctx\s+(\d+)/i', $parameters, $matches) === 1) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * The capabilities Ollama reports for the model, from the `capabilities`
     * array `/api/show` returns.
     *
     * This replaces a guess from the model NAME — `tools` for anything
     * containing `qwen`, `llama3`, `mistral` or `mixtral`, `vision` for
     * anything containing `vision` or `llava`. That guess was wrong in both
     * directions: a tool-capable model outside those four families never got
     * the token, and a distilled or quantised variant carrying one of those
     * words in its tag got it without being able to call a tool.
     *
     * Ollama's own vocabulary differs from this extension's, so only the
     * overlapping tokens are mapped. `thinking`, `insert` and `embedding` have
     * no counterpart in the model's capability field and are dropped rather
     * than invented. A response without the array — Ollama below 0.6 — yields
     * `chat`, which is what a model in the chat listing at least does.
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<string>
     */
    private function extractOllamaCapabilities(array $data): array
    {
        $reported = isset($data['capabilities']) && is_array($data['capabilities'])
            ? $data['capabilities']
            : [];
        if ($reported === []) {
            return ['chat'];
        }

        $capabilities = [];
        // `completion` is Ollama's token for a text-generating model, which is
        // what `chat` means here; a model that reports neither is not one this
        // extension can send a conversation to.
        if (in_array('completion', $reported, true)) {
            $capabilities[] = 'chat';
        }

        if (in_array('tools', $reported, true)) {
            $capabilities[] = 'tools';
        }

        if (in_array('vision', $reported, true)) {
            $capabilities[] = 'vision';
        }

        if (in_array('embedding', $reported, true)) {
            $capabilities[] = 'embeddings';
        }

        return $capabilities;
    }

    /**
     * Estimate max output tokens for Ollama models.
     *
     * Ollama doesn't expose this, so we use model-specific defaults
     * or derive from context length.
     */
    private function estimateOllamaMaxOutput(string $modelIdLower, int $contextLength): int
    {
        // Known model limits (December 2025)
        $knownLimits = [
            'qwen' => 8192,      // Qwen models typically 8K output
            'llama3' => 8192,    // Llama 3.x models
            'llama-3' => 8192,
            'mistral' => 8192,   // Mistral models
            'mixtral' => 8192,
            'gemma' => 8192,     // Gemma models
            'phi' => 4096,       // Phi models (smaller)
            'codellama' => 16384, // Code models need longer output
            'deepseek' => 8192,
            'yi' => 4096,
        ];

        // Check for known model families
        foreach ($knownLimits as $family => $limit) {
            if (str_contains($modelIdLower, $family)) {
                return $limit;
            }
        }

        // If we have context length, use 1/4 of it (capped at 16K)
        if ($contextLength > 0) {
            return min((int)($contextLength / 4), 16384);
        }

        // Default fallback
        return 4096;
    }
}
