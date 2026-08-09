<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Fixtures;

use Generator;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;

/**
 * In-memory provider adapter double that records what reaches the wire on
 * every text path: chat, single-prompt completion, streaming and tool calls.
 *
 * Used by {@see \Netresearch\NrLlm\Tests\Functional\Service\ConfigurationSnippetTagsTest}
 * to assert that a configuration's tag-selected prompt snippets arrive in the
 * effective system prompt on all four paths.
 */
final class RecordingPromptAdapter implements ProviderInterface, StreamingCapableInterface, ToolCapableInterface
{
    /** @var list<ChatMessage|array<string, mixed>> */
    public array $recordedMessages = [];

    /** @var array<string, mixed> */
    public array $recordedOptions = [];

    public ?string $recordedPrompt = null;

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $this->recordedMessages = array_values($messages);
        $this->recordedOptions  = $options;

        return $this->response($options);
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $this->recordedPrompt  = $prompt;
        $this->recordedOptions = $options;

        return $this->response($options);
    }

    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        $this->recordedMessages = array_values($messages);
        $this->recordedOptions  = $options;

        yield 'chunk';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $this->recordedMessages = array_values($messages);
        $this->recordedOptions  = $options;

        return $this->response($options);
    }

    public function supportsTools(): bool
    {
        return true;
    }

    /**
     * The system message the manager (or the provider base class) put at the
     * head of the recorded list, or null when the list carries none.
     */
    public function recordedSystemMessage(): ?string
    {
        foreach ($this->recordedMessages as $message) {
            if ($message instanceof ChatMessage) {
                if ($message->isSystem()) {
                    return $message->content;
                }

                continue;
            }

            if (($message['role'] ?? null) === 'system' && is_string($message['content'] ?? null)) {
                return $message['content'];
            }
        }

        return null;
    }

    public function recordedSystemPromptOption(): ?string
    {
        $systemPrompt = $this->recordedOptions['system_prompt'] ?? null;

        return is_string($systemPrompt) ? $systemPrompt : null;
    }

    // ------------------------------------------------------------------
    // ProviderInterface methods not exercised by the test
    // ------------------------------------------------------------------

    public function getName(): string
    {
        return 'Recording Prompt Fake';
    }

    public function getIdentifier(): string
    {
        return 'recording-prompt-fake';
    }

    public function configure(array $config): void
    {
        // intentional no-op: the double is handed out by a mocked
        // createAdapterFromModel(), so configure() is never invoked.
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supportsFeature(string|ModelCapability $feature): bool
    {
        return true;
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        return new EmbeddingResponse([], 'recording-default-model', new UsageStatistics(0, 0, 0));
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

    /**
     * @param array<string, mixed> $options
     */
    private function response(array $options): CompletionResponse
    {
        return new CompletionResponse(
            content: 'done',
            model: is_string($options['model'] ?? null) ? $options['model'] : 'recording-default-model',
            usage: new UsageStatistics(7, 3, 10),
            finishReason: 'stop',
            provider: 'recording-prompt-fake',
        );
    }
}
