<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use RuntimeException;

/**
 * A CompletionService test double that returns a fixed response, records the
 * prompts it received, and can be told to throw — so grading, aggregation,
 * and the command can be exercised without a live LLM.
 *
 * The concrete CompletionService is `final readonly` and cannot be mocked by
 * PHPUnit, so this hand-written double implements the interface instead.
 */
final class StaticCompletionService implements CompletionServiceInterface
{
    /** @var list<string> */
    public array $receivedPrompts = [];

    public function __construct(
        private readonly string $content,
        private readonly string $model = 'test-model',
        private readonly bool $throw = false,
    ) {}

    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $this->receivedPrompts[] = $prompt;
        if ($this->throw) {
            throw new RuntimeException('provider unavailable', 1794000099);
        }

        return new CompletionResponse(
            $this->content,
            $this->model,
            new UsageStatistics(0, 0, 0),
        );
    }

    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        return [];
    }

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        return $this->content;
    }

    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->complete($prompt, $options);
    }

    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->complete($prompt, $options);
    }

    public function completeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->complete($prompt, $options);
    }

    public function completeJsonForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): array
    {
        $this->receivedPrompts[] = $prompt;

        return [];
    }

    public function completeMarkdownForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): string
    {
        $this->receivedPrompts[] = $prompt;

        return $this->content;
    }

    public function completeFactualForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->complete($prompt, $options);
    }

    public function completeCreativeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->complete($prompt, $options);
    }
}
