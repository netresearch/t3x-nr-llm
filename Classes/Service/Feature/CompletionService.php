<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Budget\AutoPopulatesBeUserUidTrait;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * High-level service for text completion.
 *
 * Provides simple text generation with configurable creativity,
 * format control, and token management.
 *
 * Budget pre-flight (REC #4): when a caller does not set an explicit
 * `beUserUid` on the options, the service consults
 * `BackendUserContextResolverInterface` to find the active backend user
 * and populates the option so the BudgetMiddleware in the pipeline can
 * enforce per-user limits without every caller having to remember the
 * wiring. The resolver injection is optional so unit tests that only
 * care about the messaging path can omit it; in production DI the
 * Symfony container always autowires it from
 * `Configuration/Services.yaml`.
 */
final readonly class CompletionService implements CompletionServiceInterface
{
    use AutoPopulatesBeUserUidTrait;

    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private ?BackendUserContextResolverInterface $beUserContextResolver = null,
    ) {}

    /**
     * Generate text completion.
     *
     * @param string $prompt The user prompt
     *
     * @throws InvalidArgumentException
     */
    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        $options = $this->autoPopulateBeUserUid($options);
        $optionsArray = $options->toArray();
        $this->validateOptions($optionsArray);

        $messages = [];

        // REC #2 closure: build typed `ChatMessage` VOs at construction
        // rather than relying on `LlmServiceManager`'s back-compat
        // normalisation. Typed-from-the-source means PHPStan catches
        // shape drift earlier and the call site is self-documenting.
        $systemPrompt = $optionsArray['system_prompt'] ?? null;
        if (is_string($systemPrompt) && $systemPrompt !== '') {
            $messages[] = ChatMessage::system($systemPrompt);
        }

        $messages[] = ChatMessage::user($prompt);

        // Handle response format
        $responseFormat = $optionsArray['response_format'] ?? null;
        if (is_string($responseFormat)) {
            $normalizedFormat = $this->normalizeResponseFormat($responseFormat);
            $options = $options->withResponseFormat(
                is_string($normalizedFormat) ? $normalizedFormat : 'json',
            );
        }

        // Map stop_sequences to stop
        if ($options->getStopSequences() !== null) {
            $optionsArray = $options->toArray();
            $optionsArray['stop'] = $optionsArray['stop_sequences'];
            unset($optionsArray['stop_sequences']);

            // Create temporary options with modified array. Every typed
            // field that ChatOptions exposes must be copied across — the
            // new constructor call would otherwise silently drop the
            // budget pre-flight fields (REC #4) and let stop-sequence
            // callers bypass the BudgetMiddleware. PHPStan won't catch
            // this because every parameter is `?T = null`, so missing
            // fields look like "use the default".
            $tempOptions = new ChatOptions(
                temperature: $options->getTemperature(),
                maxTokens: $options->getMaxTokens(),
                topP: $options->getTopP(),
                frequencyPenalty: $options->getFrequencyPenalty(),
                presencePenalty: $options->getPresencePenalty(),
                responseFormat: $options->getResponseFormat(),
                provider: $options->getProvider(),
                model: $options->getModel(),
                beUserUid: $options->getBeUserUid(),
                plannedCost: $options->getPlannedCost(),
            );
            return $this->llmManager->chat($messages, $tempOptions);
        }

        return $this->llmManager->chat($messages, $options);
    }

    /**
     * Generate JSON-formatted completion.
     *
     * @param string $prompt The user prompt
     *
     * @throws InvalidArgumentException
     *
     * @return array<string, mixed> Parsed JSON response
     */
    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $options ??= new ChatOptions();
        $options = $options->withResponseFormat('json');

        $response = $this->complete($prompt, $options);

        $decoded = json_decode($response->content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Failed to decode JSON response: ' . json_last_error_msg(),
                2805117333,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                'JSON response must be an object, got ' . gettype($decoded),
                2805117334,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Generate markdown-formatted completion.
     *
     * @param string $prompt The user prompt
     *
     * @return string Markdown-formatted text
     */
    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        $options ??= new ChatOptions();
        $options = $options->withResponseFormat('markdown');

        $systemPrompt = $options->getSystemPrompt() ?? '';
        $systemPrompt .= "\n\nFormat your response in clean, well-structured Markdown.";
        $options = $options->withSystemPrompt(trim($systemPrompt));

        $response = $this->complete($prompt, $options);

        return $response->content;
    }

    /**
     * Generate completion with low creativity (factual, consistent).
     *
     * @param string $prompt The user prompt
     */
    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();

        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(0.2);
        }
        if ($options->getTopP() === null) {
            $options = $options->withTopP(0.9);
        }

        return $this->complete($prompt, $options);
    }

    /**
     * Generate completion with high creativity (diverse, creative).
     *
     * @param string $prompt The user prompt
     */
    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();

        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(1.2);
        }
        if ($options->getTopP() === null) {
            $options = $options->withTopP(1.0);
        }
        if ($options->getPresencePenalty() === null) {
            $options = $options->withPresencePenalty(0.6);
        }

        return $this->complete($prompt, $options);
    }

    /**
     * Validate completion options.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException
     */
    private function validateOptions(array $options): void
    {
        if (isset($options['temperature'])) {
            $temp = $options['temperature'];
            if (!is_numeric($temp) || $temp < 0 || $temp > 2) {
                throw new InvalidArgumentException(
                    'Temperature must be between 0.0 and 2.0',
                    3543071196,
                );
            }
        }

        if (isset($options['max_tokens'])) {
            $maxTokens = $options['max_tokens'];
            if (!is_int($maxTokens) || $maxTokens < 1) {
                throw new InvalidArgumentException(
                    'max_tokens must be a positive integer',
                    5189150391,
                );
            }
        }

        if (isset($options['top_p'])) {
            $topP = $options['top_p'];
            if (!is_numeric($topP) || $topP < 0 || $topP > 1) {
                throw new InvalidArgumentException(
                    'top_p must be between 0.0 and 1.0',
                    6248946507,
                );
            }
        }

        if (isset($options['response_format'])) {
            $format = $options['response_format'];
            if (!in_array($format, ['text', 'json', 'markdown'], true)) {
                throw new InvalidArgumentException(
                    'response_format must be "text", "json", or "markdown"',
                    2518770347,
                );
            }
        }
    }

    /**
     * Normalize response format for provider compatibility.
     *
     * @return array<string, string>|string
     */
    private function normalizeResponseFormat(string $format): array|string
    {
        return match ($format) {
            'json' => ['type' => 'json_object'],
            'markdown', 'text' => 'text',
            default => 'text',
        };
    }

    // `autoPopulateBeUserUid()` is provided by `AutoPopulatesBeUserUidTrait`
    // — shared with EmbeddingService and VisionService since the wiring
    // is identical across feature services.
}
