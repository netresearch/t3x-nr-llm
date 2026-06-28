<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Fixtures;

use BadMethodCallException;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;

/**
 * In-memory provider adapter double that is BOTH a {@see ProviderInterface}
 * and a {@see ToolCapableInterface}.
 *
 * Used by {@see \Netresearch\NrLlm\Tests\Functional\Service\LlmServiceManagerToolsConfigurationTest}
 * to prove `LlmServiceManager::chatWithToolsForConfiguration()` resolves the
 * adapter from the run's configuration and forwards through
 * `chatCompletionWithTools()`. It records the tools/options the manager
 * forwards and answers with a single canned `ToolCall` so the round trip can
 * be asserted without a real provider or HTTP traffic. Methods the test does
 * not exercise throw so an accidental call is loud rather than silent.
 */
final class RecordingToolAdapter implements ProviderInterface, ToolCapableInterface
{
    /** @var list<ToolSpec>|null */
    public ?array $recordedTools = null;

    /** @var array<string, mixed>|null */
    public ?array $recordedOptions = null;

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $this->recordedTools   = $tools;
        $this->recordedOptions = $options;

        return new CompletionResponse(
            content: '',
            model: is_string($options['model'] ?? null) ? $options['model'] : 'unknown',
            usage: new UsageStatistics(7, 3, 10),
            finishReason: 'tool_calls',
            provider: 'recording-fake',
            toolCalls: [ToolCall::function('call_0', 'fetch_logs', ['limit' => 5])],
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // ProviderInterface methods not exercised by the test
    // ------------------------------------------------------------------

    public function getName(): string
    {
        return 'Recording Fake';
    }

    public function getIdentifier(): string
    {
        return 'recording-fake';
    }

    public function configure(array $config): void {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function supportsFeature(string|ModelCapability $feature): bool
    {
        return true;
    }

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        throw new BadMethodCallException('chatCompletion() is not used in this test', 1782748810);
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        throw new BadMethodCallException('complete() is not used in this test', 1782748811);
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        throw new BadMethodCallException('embeddings() is not used in this test', 1782748812);
    }

    public function getAvailableModels(): array
    {
        return [];
    }

    public function getDefaultModel(): string
    {
        return 'recording-default-model';
    }

    public function testConnection(): array
    {
        return ['success' => true, 'message' => 'ok'];
    }
}
