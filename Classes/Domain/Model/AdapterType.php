<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Defines supported LLM provider adapter types.
 *
 * Each case represents a distinct API provider with its own endpoint format,
 * authentication method, and request/response structure.
 */
enum AdapterType: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case OpenRouter = 'openrouter';
    case Mistral = 'mistral';
    case Groq = 'groq';
    case Together = 'together';
    case Fireworks = 'fireworks';
    case Perplexity = 'perplexity';
    case Ollama = 'ollama';
    case AzureOpenAI = 'azure_openai';
    case Custom = 'custom';

    /**
     * Human-readable label for display in UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic (Claude)',
            self::Gemini => 'Google Gemini',
            self::OpenRouter => 'OpenRouter',
            self::Mistral => 'Mistral AI',
            self::Groq => 'Groq',
            self::Together => 'Together AI',
            self::Fireworks => 'Fireworks AI',
            self::Perplexity => 'Perplexity',
            self::Ollama => 'Ollama (Local)',
            self::AzureOpenAI => 'Azure OpenAI',
            self::Custom => 'Custom (OpenAI-compatible)',
        };
    }

    /**
     * Default API endpoint URL for this adapter type.
     */
    public function defaultEndpoint(): string
    {
        return match ($this) {
            self::OpenAI => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
            self::OpenRouter => 'https://openrouter.ai/api/v1',
            self::Mistral => 'https://api.mistral.ai/v1',
            self::Groq => 'https://api.groq.com/openai/v1',
            self::Together => 'https://api.together.xyz/v1',
            self::Fireworks => 'https://api.fireworks.ai/inference/v1',
            // Bare host: Perplexity's OpenAI-compatible base has no version segment
            // (the client appends "/chat/completions" directly).
            self::Perplexity => 'https://api.perplexity.ai',
            // Bare host: OllamaProvider adds the "api/" segment to each request path
            // itself, so its base URL must NOT include it (otherwise "/api/api/tags").
            self::Ollama => 'http://localhost:11434',
            self::AzureOpenAI, self::Custom => '',
        };
    }

    /**
     * Whether this adapter requires an API key.
     */
    public function requiresApiKey(): bool
    {
        return match ($this) {
            self::Ollama => false,
            default => true,
        };
    }

    /**
     * Get all adapter types as value => label array for TCA select fields.
     *
     * @return array<string, string>
     */
    public static function toSelectArray(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            $result[$case->value] = $case->label();
        }
        return $result;
    }
}
