<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Budget\AutoPopulatesBeUserUidTrait;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;

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
        private JsonSchemaValidator $schemaValidator = new JsonSchemaValidator(),
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

        $options = $this->normalizeOptionsResponseFormat($options, $optionsArray);

        // Pass the (immutable) options straight through: they already carry
        // every typed field — including stopSequences, which toArray() emits as
        // `stop_sequences` for the providers that read it. The previous
        // stop-sequence branch rebuilt ChatOptions WITHOUT stopSequences and so
        // silently dropped them for every provider; passing $options directly
        // preserves them (and all budget pre-flight fields).
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

        return $this->decodeJsonResponse($this->complete($prompt, $options)->content);
    }

    /**
     * Generate a completion whose JSON response is validated against a subset
     * JSON Schema, with one controlled repair round-trip on a decode or schema
     * failure (ADR-082).
     *
     * Provider-agnostic: the schema is injected into the prompt and JSON mode is
     * requested, then the decoded payload is validated locally with
     * {@see JsonSchemaValidator}. A native provider structured-output guarantee
     * is a separate concern (see ADR-082).
     *
     * @param array<string, mixed> $schema Subset JSON Schema: top-level `type`,
     *                                     object `required` keys and per-key
     *                                     `properties` types are enforced.
     *
     * @throws InvalidArgumentException when the response still fails to match the
     *                                  schema after one repair attempt
     *
     * @return array<string, mixed> The decoded, schema-valid JSON payload
     */
    public function completeStructured(string $prompt, array $schema, ?ChatOptions $options = null): array
    {
        $options    = ($options ?? new ChatOptions())->withResponseFormat('json');
        $schemaJson = $this->encodeSchema($schema);

        $first  = $this->complete($this->withSchemaInstruction($prompt, $schemaJson), $options)->content;
        $result = $this->decodeAndValidate($first, $schema);
        if ($result !== null) {
            return $result;
        }

        // One controlled repair round-trip: show the model its invalid output
        // and the schema, and ask again.
        $repaired = $this->complete($this->withRepairInstruction($prompt, $schemaJson, $first), $options)->content;
        $result   = $this->decodeAndValidate($repaired, $schema);
        if ($result !== null) {
            return $result;
        }

        throw new InvalidArgumentException(
            'Structured completion did not match the required schema after one repair attempt.',
            1784500001,
        );
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
        $options = $this->applyMarkdownPresets($options ?? new ChatOptions());

        return $this->complete($prompt, $options)->content;
    }

    /**
     * Generate completion with low creativity (factual, consistent).
     *
     * @param string $prompt The user prompt
     */
    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->complete($prompt, $this->applyFactualPresets($options ?? new ChatOptions()));
    }

    /**
     * Generate completion with high creativity (diverse, creative).
     *
     * @param string $prompt The user prompt
     */
    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->complete($prompt, $this->applyCreativePresets($options ?? new ChatOptions()));
    }

    public function completeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        $options      = $this->autoPopulateBeUserUid($options ?? new ChatOptions());
        $optionsArray = $options->toArray();
        $this->validateOptions($optionsArray);
        $options = $this->normalizeOptionsResponseFormat($options, $optionsArray);

        return $this->llmManager->completeForConfiguration($prompt, $configuration, $options);
    }

    public function completeJsonForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): array
    {
        $options = ($options ?? new ChatOptions())->withResponseFormat('json');

        return $this->decodeJsonResponse(
            $this->completeForConfiguration($prompt, $configuration, $options)->content,
        );
    }

    /**
     * The named-configuration counterpart to {@see completeStructured()}.
     *
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException when the response still fails to match the
     *                                  schema after one repair attempt
     *
     * @return array<string, mixed>
     */
    public function completeStructuredForConfiguration(string $prompt, LlmConfiguration $configuration, array $schema, ?ChatOptions $options = null): array
    {
        $options    = ($options ?? new ChatOptions())->withResponseFormat('json');
        $schemaJson = $this->encodeSchema($schema);

        $first  = $this->completeForConfiguration($this->withSchemaInstruction($prompt, $schemaJson), $configuration, $options)->content;
        $result = $this->decodeAndValidate($first, $schema);
        if ($result !== null) {
            return $result;
        }

        $repaired = $this->completeForConfiguration($this->withRepairInstruction($prompt, $schemaJson, $first), $configuration, $options)->content;
        $result   = $this->decodeAndValidate($repaired, $schema);
        if ($result !== null) {
            return $result;
        }

        throw new InvalidArgumentException(
            'Structured completion did not match the required schema after one repair attempt.',
            1784500002,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function encodeSchema(array $schema): string
    {
        return json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function withSchemaInstruction(string $prompt, string $schemaJson): string
    {
        return $prompt
            . "\n\nRespond with ONLY a single JSON value conforming to this JSON Schema. "
            . "Do not wrap it in Markdown code fences and do not add any prose:\n"
            . $schemaJson;
    }

    private function withRepairInstruction(string $prompt, string $schemaJson, string $invalid): string
    {
        // Mark truncation so the model does not mistake a system-cut JSON tail
        // for the model's own malformed output.
        $snippet = mb_substr($invalid, 0, 2000);
        if (mb_strlen($invalid) > 2000) {
            $snippet .= "\n[... truncated ...]";
        }

        return $this->withSchemaInstruction($prompt, $schemaJson)
            . "\n\nYour previous response did not conform to the schema. It was:\n"
            . $snippet;
    }

    /**
     * Decode a response and validate it against the schema. Returns the decoded
     * payload only when it is a schema-valid JSON value, null otherwise (an
     * invalid decode or a schema mismatch), so the caller can trigger a repair.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|null
     */
    private function decodeAndValidate(string $content, array $schema): ?array
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (!$this->schemaValidator->validate($decoded, $schema)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function completeMarkdownForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): string
    {
        $options = $this->applyMarkdownPresets($options ?? new ChatOptions());

        return $this->completeForConfiguration($prompt, $configuration, $options)->content;
    }

    public function completeFactualForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->completeForConfiguration($prompt, $configuration, $this->applyFactualPresets($options ?? new ChatOptions()));
    }

    public function completeCreativeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->completeForConfiguration($prompt, $configuration, $this->applyCreativePresets($options ?? new ChatOptions()));
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

    /**
     * Apply the provider-compatible response-format normalization to the options.
     *
     * Shared by the instance-default and configuration completion paths so both
     * emit the identical `response_format` the providers expect.
     *
     * @param array<string, mixed> $optionsArray already-materialized $options->toArray()
     */
    private function normalizeOptionsResponseFormat(ChatOptions $options, array $optionsArray): ChatOptions
    {
        $responseFormat = $optionsArray['response_format'] ?? null;
        if (!is_string($responseFormat)) {
            return $options;
        }

        $normalizedFormat = $this->normalizeResponseFormat($responseFormat);

        return $options->withResponseFormat(is_string($normalizedFormat) ? $normalizedFormat : 'json');
    }

    /**
     * Decode a JSON completion into an object array.
     *
     * @throws InvalidArgumentException when the content is not a valid JSON object
     *
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(string $content): array
    {
        $decoded = json_decode($content, true);

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
     * Request Markdown output: force the markdown format and augment the system
     * prompt. Shared by the instance-default and configuration paths.
     */
    private function applyMarkdownPresets(ChatOptions $options): ChatOptions
    {
        $options = $options->withResponseFormat('markdown');

        $systemPrompt = $options->getSystemPrompt() ?? '';
        $systemPrompt .= "\n\nFormat your response in clean, well-structured Markdown.";

        return $options->withSystemPrompt(trim($systemPrompt));
    }

    /**
     * Low-creativity (factual, consistent) presets, applied only where unset.
     * Shared by the instance-default and configuration paths.
     */
    private function applyFactualPresets(ChatOptions $options): ChatOptions
    {
        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(0.2);
        }
        if ($options->getTopP() === null) {
            $options = $options->withTopP(0.9);
        }

        return $options;
    }

    /**
     * High-creativity (diverse) presets, applied only where unset.
     * Shared by the instance-default and configuration paths.
     */
    private function applyCreativePresets(ChatOptions $options): ChatOptions
    {
        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(1.2);
        }
        if ($options->getTopP() === null) {
            $options = $options->withTopP(1.0);
        }
        if ($options->getPresencePenalty() === null) {
            $options = $options->withPresencePenalty(0.6);
        }

        return $options;
    }

    // `autoPopulateBeUserUid()` is provided by `AutoPopulatesBeUserUidTrait`
    // — shared with EmbeddingService and VisionService since the wiring
    // is identical across feature services.
}
