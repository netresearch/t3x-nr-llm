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
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;

/**
 * Tool-capable provider double that scripts a single agent round trip: the
 * first {@see chatCompletionWithTools()} call asks for one `fetch_logs` tool
 * call, every subsequent call answers plainly with no tools.
 *
 * It lets {@see \Netresearch\NrLlm\Tests\Functional\Controller\Backend\ToolPlaygroundControllerTest}
 * drive `ToolLoopService::runLoop()` through one real execute-and-replay cycle
 * (iteration 1 = tool call, iteration 2 = final answer) so the playground's
 * runAction trace can be asserted end-to-end without a real provider. Methods
 * the loop never reaches throw so an accidental call is loud, not silent.
 */
final class ScriptedToolAdapter implements ProviderInterface, ToolCapableInterface
{
    private int $toolCallCount = 0;

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $this->toolCallCount++;
        $model = is_string($options['model'] ?? null) ? $options['model'] : 'unknown';

        // First round: request a single tool call. Later rounds: stop with a
        // plain answer so the loop terminates after replaying the tool result.
        if ($this->toolCallCount === 1) {
            return new CompletionResponse(
                content: '',
                model: $model,
                usage: new UsageStatistics(7, 3, 10),
                finishReason: 'tool_calls',
                provider: 'scripted-fake',
                toolCalls: [ToolCall::function('call_0', 'fetch_logs', ['limit' => 5])],
            );
        }

        return new CompletionResponse(
            content: 'Here are your recent logs.',
            model: $model,
            usage: new UsageStatistics(5, 4, 9),
            finishReason: 'stop',
            provider: 'scripted-fake',
            toolCalls: null,
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // ProviderInterface methods not exercised by the loop
    // ------------------------------------------------------------------

    public function getName(): string
    {
        return 'Scripted Fake';
    }

    public function getIdentifier(): string
    {
        return 'scripted-fake';
    }

    public function configure(array $config): void
    {
        // intentional no-op: the adapter is resolved directly via a mocked
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

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        throw new BadMethodCallException('chatCompletion() is not used in this test', 1782749810);
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        throw new BadMethodCallException('complete() is not used in this test', 1782749811);
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        throw new BadMethodCallException('embeddings() is not used in this test', 1782749812);
    }

    public function getAvailableModels(): array
    {
        return [];
    }

    public function getDefaultModel(): string
    {
        return 'scripted-default-model';
    }

    public function testConnection(): array
    {
        return ['success' => true, 'message' => 'ok'];
    }
}
