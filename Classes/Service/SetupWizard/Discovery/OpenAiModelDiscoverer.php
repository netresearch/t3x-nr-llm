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
 * OpenAI model discovery: Bearer-authenticated /models listing, relevance
 * filter, spec-catalog enrichment, recommended-first sort; falls back to a
 * static catalog derived from the same spec table.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class OpenAiModelDiscoverer extends AbstractModelDiscoverer
{
    /**
     * Discover OpenAI models via API.
     */
    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . self::MODELS_PATH)
                ->withHeader('Authorization', self::AUTH_BEARER_PREFIX . $apiKey)
                ->withHeader('Content-Type', 'application/json');

            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError('openai', $response->getStatusCode());

                return DiscoveryResult::fallback($this->getOpenAIFallbackModels());
            }

            $data = $this->decodeModelListBody('openai', $response->getBody()->getContents());
            $dataList = is_array($data) && isset($data['data']) && is_array($data['data'])
                ? $data['data']
                : [];

            $models = [];
            foreach ($dataList as $model) {
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

                if (!$this->isRelevantOpenAIModel($modelId)) {
                    continue;
                }

                $models[] = $this->enrichOpenAIModel($modelId);
            }

            // Sort by recommendation
            usort($models, fn(DiscoveredModel $a, DiscoveredModel $b): int => $b->recommended <=> $a->recommended);

            return $models !== [] ? DiscoveryResult::live($models) : DiscoveryResult::fallback($this->getOpenAIFallbackModels());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('openai', $e);

            return DiscoveryResult::fallback($this->getOpenAIFallbackModels());
        }
    }

    /**
     * Check if OpenAI model is relevant (not deprecated/internal).
     */
    private function isRelevantOpenAIModel(string $modelId): bool
    {
        // Include current-generation models
        $patterns = [
            '/^gpt-5/',
            '/^gpt-4o/',
            '/^gpt-4-turbo/',
            '/^gpt-4\./',       // gpt-4.1, gpt-4.1-mini, etc.
            '/^o[1234]-/',      // o1, o3, o4 series
            '/^gpt-image/',
            '/^chatgpt-/',      // chatgpt-4o-latest etc.
            '/^tts-/',          // tts-1, tts-1-hd (text-to-speech)
            '/-tts$/',          // gpt-4o-mini-tts etc.
            '/^whisper-/',      // whisper-1 (transcription)
            '/-transcribe$/',   // gpt-4o-transcribe etc.
            '/^dall-e-/',       // dall-e-3 (image generation)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $modelId)) {
                return true;
            }
        }

        // Exclude known irrelevant models
        if (str_starts_with($modelId, 'text-embedding')
            || str_starts_with($modelId, 'babbage')
            || str_starts_with($modelId, 'davinci')
            || str_contains($modelId, 'instruct')
            || str_contains($modelId, 'realtime')
            || str_contains($modelId, '-search')
        ) {
            return false;
        }

        // Include anything else with a gpt- or o- prefix
        return str_starts_with($modelId, 'gpt-') || preg_match('/^o\d/', $modelId) === 1;
    }

    /**
     * Enrich OpenAI model with known specifications.
     */
    private function enrichOpenAIModel(string $modelId): DiscoveredModel
    {
        $spec = $this->openAIModelSpecs()[$modelId] ?? [
            'name' => $modelId,
            'description' => 'OpenAI model',
            'capabilities' => $this->defaultOpenAICapabilities($modelId),
            'contextLength' => 0,
            'maxOutputTokens' => 0,
            'costInput' => 0,
            'costOutput' => 0,
            'recommended' => false,
        ];

        return new DiscoveredModel(
            modelId: $modelId,
            name: $spec['name'],
            description: $spec['description'],
            capabilities: $spec['capabilities'],
            contextLength: $spec['contextLength'],
            maxOutputTokens: $spec['maxOutputTokens'],
            costInput: $spec['costInput'],
            costOutput: $spec['costOutput'],
            recommended: $spec['recommended'],
        );
    }

    /**
     * June 2026 OpenAI model specifications, keyed by model id.
     *
     * @return array<string, array{name: string, description: string, capabilities: array<string>, contextLength: int, maxOutputTokens: int, costInput: int, costOutput: int, recommended: bool}>
     */
    private function openAIModelSpecs(): array
    {
        return [
            'gpt-5.5' => [
                'name' => 'GPT-5.5',
                'description' => 'Latest flagship model with enhanced reasoning',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 500,
                'costOutput' => 3000,
                'recommended' => true,
            ],
            'gpt-5.3' => [
                'name' => 'GPT-5.3',
                'description' => 'Flagship model with enhanced reasoning',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 175,
                'costOutput' => 1400,
                'recommended' => true,
            ],
            'gpt-5.3-chat-latest' => [
                'name' => 'GPT-5.3 Chat',
                'description' => 'Fast responses for interactive use',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 400000,
                'maxOutputTokens' => 32000,
                'costInput' => 100,
                'costOutput' => 400,
                'recommended' => true,
            ],
            'gpt-5.3-mini' => [
                'name' => 'GPT-5.3 Mini',
                'description' => 'Small, fast, cost-effective',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 200000,
                'maxOutputTokens' => 32000,
                'costInput' => 30,
                'costOutput' => 120,
                'recommended' => true,
            ],
            'gpt-5.2' => [
                'name' => 'GPT-5.2 Thinking',
                'description' => 'Flagship model for coding, reasoning, and agentic tasks',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 175,
                'costOutput' => 1400,
                'recommended' => false,
            ],
            'gpt-5.2-pro' => [
                'name' => 'GPT-5.2 Pro',
                'description' => 'Extended thinking for complex tasks',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 350,
                'costOutput' => 2800,
                'recommended' => false,
            ],
            'gpt-5.2-chat-latest' => [
                'name' => 'GPT-5.2 Instant',
                'description' => 'Fast responses for interactive use',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 400000,
                'maxOutputTokens' => 32000,
                'costInput' => 100,
                'costOutput' => 400,
                'recommended' => false,
            ],
            'gpt-5' => [
                'name' => 'GPT-5',
                'description' => 'Previous generation flagship model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 200000,
                'maxOutputTokens' => 64000,
                'costInput' => 150,
                'costOutput' => 600,
                'recommended' => false,
            ],
            'gpt-5-mini' => [
                'name' => 'GPT-5 Mini',
                'description' => 'Smaller, faster, cost-effective',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 128000,
                'maxOutputTokens' => 32000,
                'costInput' => 30,
                'costOutput' => 120,
                'recommended' => false,
            ],
            'o4-mini' => [
                'name' => 'O4 Mini',
                'description' => 'Fast reasoning for math, coding, visual tasks',
                'capabilities' => ['chat', 'vision', 'tools'],
                'contextLength' => 200000,
                'maxOutputTokens' => 100000,
                'costInput' => 110,
                'costOutput' => 440,
                'recommended' => false,
            ],
            'o3' => [
                'name' => 'O3',
                'description' => 'Advanced reasoning model',
                'capabilities' => ['chat', 'vision', 'tools'],
                'contextLength' => 200000,
                'maxOutputTokens' => 100000,
                'costInput' => 200,
                'costOutput' => 800,
                'recommended' => false,
            ],
            'gpt-4o' => [
                'name' => 'GPT-4o',
                'description' => 'Legacy multimodal model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 128000,
                'maxOutputTokens' => 16384,
                'costInput' => 250,
                'costOutput' => 1000,
                'recommended' => false,
            ],
            'gpt-4.1' => [
                'name' => 'GPT-4.1',
                'description' => 'Coding and instruction-following model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 1047576,
                'maxOutputTokens' => 32768,
                'costInput' => 200,
                'costOutput' => 800,
                'recommended' => false,
            ],
            'gpt-4.1-mini' => [
                'name' => 'GPT-4.1 Mini',
                'description' => 'Fast coding model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 1047576,
                'maxOutputTokens' => 32768,
                'costInput' => 40,
                'costOutput' => 160,
                'recommended' => false,
            ],
            // Specialized models — see specializedSpec() for the shared shape.
            'gpt-image-2' => $this->specializedSpec('GPT Image 2', 'Image generation model', 'image'),
            'tts-1' => $this->specializedSpec('TTS-1', 'Text-to-speech model optimized for speed', 'text_to_speech'),
            'tts-1-hd' => $this->specializedSpec('TTS-1 HD', 'Text-to-speech model optimized for quality', 'text_to_speech'),
            'whisper-1' => $this->specializedSpec('Whisper', 'Speech-to-text transcription model', 'transcription'),
        ];
    }

    /**
     * Derive default capabilities from the model id shape for OpenAI models
     * without an explicit spec entry. The returned values match the
     * ModelCapability enum (image / text_to_speech / transcription / audio /
     * chat).
     *
     * The id is the only evidence there is here: GET /v1/models returns id,
     * object, created and owned_by, and nothing that describes what a model
     * can do. That is why `audio` is keyed on the id the same way `tts-`,
     * `whisper-` and `gpt-image` already are.
     *
     * `audio` is the chat-completions modality -- a model that takes and
     * returns speech inside an ordinary chat call -- and is therefore a
     * different capability from `text_to_speech` and `transcription`, which
     * name the dedicated TTS and Whisper endpoints. It stays alongside `chat`
     * for that reason: the model answers a prompt, it is just not limited to
     * text (#913).
     *
     * @return array<string>
     */
    private function defaultOpenAICapabilities(string $modelId): array
    {
        return match (true) {
            str_starts_with($modelId, 'dall-e-'),
            str_starts_with($modelId, 'gpt-image') => ['image'],
            str_starts_with($modelId, 'tts-'),
            str_ends_with($modelId, '-tts') => ['text_to_speech'],
            str_starts_with($modelId, 'whisper-'),
            str_ends_with($modelId, '-transcribe') => ['transcription'],
            str_contains($modelId, '-audio') => ['chat', 'audio'],
            default => ['chat'],
        };
    }

    /**
     * Build a spec entry for a specialized (non-chat) model.
     *
     * Context length, max output tokens, and token-based costs do not apply
     * to these models (priced per image / character / minute), hence 0.
     * The capability value matches the ModelCapability enum.
     *
     * @return array{name: string, description: string, capabilities: array<string>, contextLength: int, maxOutputTokens: int, costInput: int, costOutput: int, recommended: bool}
     */
    private function specializedSpec(string $name, string $description, string $capability): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'capabilities' => [$capability],
            'contextLength' => 0,
            'maxOutputTokens' => 0,
            'costInput' => 0,
            'costOutput' => 0,
            'recommended' => false,
        ];
    }

    /**
     * Get OpenAI fallback models (when API discovery fails).
     *
     * @return array<DiscoveredModel>
     */
    private function getOpenAIFallbackModels(): array
    {
        return [
            $this->enrichOpenAIModel('gpt-5.5'),
            $this->enrichOpenAIModel('gpt-5.3'),
            $this->enrichOpenAIModel('gpt-5.3-chat-latest'),
            $this->enrichOpenAIModel('gpt-5.3-mini'),
            $this->enrichOpenAIModel('gpt-5.2'),
            $this->enrichOpenAIModel('gpt-5.2-chat-latest'),
            $this->enrichOpenAIModel('gpt-5-mini'),
            $this->enrichOpenAIModel('gpt-4o'),
            $this->enrichOpenAIModel('o4-mini'),
            $this->enrichOpenAIModel('o3'),
            $this->enrichOpenAIModel('gpt-image-2'),
            $this->enrichOpenAIModel('tts-1'),
            $this->enrichOpenAIModel('tts-1-hd'),
            $this->enrichOpenAIModel('whisper-1'),
        ];
    }
}
