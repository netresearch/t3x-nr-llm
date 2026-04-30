<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Public surface of the high-level text-completion service.
 *
 * Consumers (controllers, schedulers, tests, downstream extensions)
 * should depend on this interface rather than the concrete
 * `CompletionService` so the implementation can be substituted without
 * inheritance.
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
}
