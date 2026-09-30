<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\StructuredCompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Public surface of the high-level text-completion service.
 *
 * Consumers (controllers, schedulers, tests, downstream extensions)
 * should depend on this interface rather than the concrete
 * `CompletionService` so the implementation can be substituted without
 * inheritance.
 *
 * @api
 */
interface CompletionServiceInterface
{
    /**
     * Generate a text completion.
     *
     * @throws InvalidArgumentException
     */
    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse;

    /**
     * Generate a JSON-formatted completion (response_format=json).
     *
     * @throws InvalidArgumentException when the response is not valid JSON
     *
     * @return array<string, mixed> Parsed JSON response
     */
    public function completeJson(string $prompt, ?ChatOptions $options = null): array;

    /**
     * Generate a completion validated against a subset JSON Schema, with one
     * controlled repair round-trip on a decode or schema failure (ADR-082).
     *
     * @param array<string, mixed> $schema Subset JSON Schema (top-level `type`,
     *                                     object `required` keys, per-key
     *                                     `properties` types)
     *
     * @throws InvalidArgumentException when the response still fails to match the
     *                                  schema after one repair attempt
     *
     * @return StructuredCompletionResponse the decoded, schema-valid payload with the answering response and the usage of every attempt
     */
    public function completeStructured(string $prompt, array $schema, ?ChatOptions $options = null): StructuredCompletionResponse;

    /**
     * Generate a Markdown-formatted completion (system prompt augmented to request Markdown).
     */
    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string;

    /**
     * Generate a low-creativity completion (factual / consistent presets).
     */
    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse;

    /**
     * Generate a high-creativity completion (creative / diverse presets).
     */
    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse;

    /**
     * Generate a text completion against a specific LlmConfiguration record.
     *
     * The named-configuration counterpart to {@see complete()}: the given
     * configuration decides provider, model, API key and cost attribution,
     * while per-user budget and idempotency metadata are threaded through so
     * BudgetMiddleware still enforces the configuration's and the user's
     * limits. Mirrors {@see EmbeddingServiceInterface::embedForConfiguration()}.
     *
     * @param ModelResolution|null $resolution a routing decision the caller already took for this
     *                                         configuration (#922) — the model it checked is the model that
     *                                         serves; a fallback configuration still resolves for itself
     *
     * @throws InvalidArgumentException
     */
    public function completeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null, ?ModelResolution $resolution = null): CompletionResponse;

    /**
     * Generate a JSON-formatted completion against a specific LlmConfiguration record.
     *
     * @throws InvalidArgumentException when the response is not valid JSON
     *
     * @return array<string, mixed> Parsed JSON response
     */
    public function completeJsonForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): array;

    /**
     * The named-configuration counterpart to {@see completeStructured()}.
     *
     * @param array<string, mixed> $schema
     * @param ModelResolution|null $resolution as {@see completeForConfiguration()}, for every attempt
     *
     * @throws InvalidArgumentException when the response still fails to match the
     *                                  schema after one repair attempt
     */
    public function completeStructuredForConfiguration(string $prompt, LlmConfiguration $configuration, array $schema, ?ChatOptions $options = null, ?ModelResolution $resolution = null): StructuredCompletionResponse;

    /**
     * Generate a Markdown-formatted completion against a specific LlmConfiguration record.
     */
    public function completeMarkdownForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): string;

    /**
     * Generate a low-creativity completion (factual presets) against a specific LlmConfiguration record.
     */
    public function completeFactualForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse;

    /**
     * Generate a high-creativity completion (creative presets) against a specific LlmConfiguration record.
     */
    public function completeCreativeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse;
}
