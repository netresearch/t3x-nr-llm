<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;

/**
 * Detects LLM provider type from endpoint URL.
 *
 * Supports detection of:
 * - OpenAI (api.openai.com)
 * - Anthropic (api.anthropic.com)
 * - Google Gemini (generativelanguage.googleapis.com)
 * - OpenRouter (openrouter.ai)
 * - Mistral (api.mistral.ai)
 * - Groq (api.groq.com)
 * - Ollama (localhost:11434 or ollama in hostname)
 * - Azure OpenAI (*.openai.azure.com)
 * - Custom/Unknown endpoints
 */
final class ProviderDetector
{
    /**
     * Detection patterns: [pattern => [adapterType, suggestedName, confidence]].
     *
     * @var array<string, array{0: string, 1: string, 2: float}>
     */
    private const array DETECTION_PATTERNS = [
        // OpenAI
        'api.openai.com' => ['openai', 'OpenAI', 1.0],
        'openai.com' => ['openai', 'OpenAI', 0.9],

        // Anthropic
        'api.anthropic.com' => ['anthropic', 'Anthropic', 1.0],
        'anthropic.com' => ['anthropic', 'Anthropic', 0.9],

        // Google Gemini
        'generativelanguage.googleapis.com' => ['gemini', 'Google Gemini', 1.0],
        'aiplatform.googleapis.com' => ['gemini', 'Google Vertex AI', 0.95],

        // OpenRouter
        'openrouter.ai' => ['openrouter', 'OpenRouter', 1.0],

        // Mistral
        'api.mistral.ai' => ['mistral', 'Mistral AI', 1.0],
        'mistral.ai' => ['mistral', 'Mistral AI', 0.9],

        // Groq
        'api.groq.com' => ['groq', 'Groq', 1.0],
        'groq.com' => ['groq', 'Groq', 0.9],

        // Together AI
        'api.together.xyz' => ['together', 'Together AI', 1.0],

        // Fireworks
        'api.fireworks.ai' => ['fireworks', 'Fireworks AI', 1.0],

        // Perplexity
        'api.perplexity.ai' => ['perplexity', 'Perplexity', 1.0],
    ];

    /**
     * Detect provider from endpoint URL.
     */
    public function detect(string $endpoint): DetectedProvider
    {
        $endpoint = $this->normalizeEndpoint($endpoint);
        $parsedUrl = parse_url($endpoint);
        $host = $parsedUrl['host'] ?? '';
        $port = $parsedUrl['port'] ?? null;

        // Check for Ollama (local)
        if ($this->isOllamaEndpoint($host, $port)) {
            return new DetectedProvider(
                adapterType: 'ollama',
                suggestedName: 'Local Ollama',
                endpoint: $endpoint,
                confidence: 1.0,
                metadata: ['local' => true],
            );
        }

        // Check for Azure OpenAI
        if ($this->isAzureOpenAI($host)) {
            $resourceName = $this->extractAzureResourceName($host);
            return new DetectedProvider(
                adapterType: 'azure_openai',
                suggestedName: 'Azure OpenAI' . ($resourceName !== '' ? " ({$resourceName})" : ''),
                endpoint: $endpoint,
                confidence: 1.0,
                metadata: ['resourceName' => $resourceName],
            );
        }

        // Check known patterns
        foreach (self::DETECTION_PATTERNS as $pattern => [$adapterType, $suggestedName, $confidence]) {
            if (str_contains($host, $pattern)) {
                return new DetectedProvider(
                    adapterType: $adapterType,
                    suggestedName: $suggestedName,
                    endpoint: $endpoint,
                    confidence: $confidence,
                );
            }
        }

        // Check for OpenAI-compatible endpoints (common pattern)
        if ($this->looksLikeOpenAICompatible($endpoint)) {
            return new DetectedProvider(
                adapterType: 'openai',
                suggestedName: 'OpenAI-Compatible (' . $host . ')',
                endpoint: $endpoint,
                confidence: 0.6,
                metadata: ['openaiCompatible' => true],
            );
        }

        // Unknown - default to OpenAI adapter as it's the most common API format
        return new DetectedProvider(
            adapterType: 'openai',
            suggestedName: 'Custom Provider (' . $host . ')',
            endpoint: $endpoint,
            confidence: 0.3,
            metadata: ['unknown' => true, 'openaiCompatible' => true],
        );
    }

    /**
     * Get all supported adapter types.
     *
     * @return array<string, string>
     */
    public function getSupportedAdapterTypes(): array
    {
        return [
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'gemini' => 'Google Gemini',
            'openrouter' => 'OpenRouter',
            'mistral' => 'Mistral AI',
            'groq' => 'Groq',
            'ollama' => 'Ollama (Local)',
            'azure_openai' => 'Azure OpenAI',
            'together' => 'Together AI',
            'fireworks' => 'Fireworks AI',
            'perplexity' => 'Perplexity',
            'custom' => 'Custom/Other',
        ];
    }

    /**
     * Normalize endpoint URL.
     */
    private function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        // Add https:// if no scheme
        if (!str_starts_with($endpoint, 'http://') && !str_starts_with($endpoint, 'https://')) {
            // Local addresses use http
            if (str_starts_with($endpoint, 'localhost') || str_starts_with($endpoint, '127.0.0.1')) {
                $endpoint = 'http://' . $endpoint;
            } else {
                $endpoint = 'https://' . $endpoint;
            }
        }

        // Remove trailing slash
        return rtrim($endpoint, '/');
    }

    /**
     * Check if endpoint is Ollama.
     */
    private function isOllamaEndpoint(string $host, ?int $port): bool
    {
        // Check for Ollama hostname
        if (str_contains(strtolower($host), 'ollama')) {
            return true;
        }

        // Check for default Ollama port (localhost or any host)
        return $port === 11434;
    }

    /**
     * Check if endpoint is Azure OpenAI.
     */
    private function isAzureOpenAI(string $host): bool
    {
        return str_ends_with($host, '.openai.azure.com');
    }

    /**
     * Extract Azure resource name from hostname.
     */
    private function extractAzureResourceName(string $host): string
    {
        if (preg_match('/^([^.]+)\.openai\.azure\.com$/', $host, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Check if endpoint looks like OpenAI-compatible API.
     */
    private function looksLikeOpenAICompatible(string $endpoint): bool
    {
        // Check for common OpenAI-compatible path patterns
        $openAIPaths = ['/v1/chat/completions', '/v1/completions', '/v1/models', '/v1/embeddings'];

        foreach ($openAIPaths as $path) {
            if (str_contains($endpoint, $path)) {
                return true;
            }
        }

        // Check for 'openai' in the URL (common for compatible proxies)
        if (str_contains(strtolower($endpoint), 'openai')) {
            return true;
        }

        return false;
    }
}
