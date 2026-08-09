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
 * first {@see chatCompletionWithTools()} call asks for one tool call — by
 * default `fetch_logs` — and every subsequent call answers plainly with no
 * tools.
 *
 * It lets {@see \Netresearch\NrLlm\Tests\Functional\Controller\Backend\ToolPlaygroundControllerTest}
 * drive `ToolLoopService::runLoop()` through one real execute-and-replay cycle
 * (iteration 1 = tool call, iteration 2 = final answer) so the playground's
 * runAction trace can be asserted end-to-end without a real provider. Methods
 * the loop never reaches throw so an accidental call is loud, not silent.
 *
 * The scripted call is configurable so a run can be driven onto a tool other
 * than the read-only default — {@see \Netresearch\NrLlm\Tests\Functional\Service\Agent\WritePathAcceptanceTest}
 * scripts the writing tool, where the second provider call happens only after
 * the approval resume. The counter is per instance, so the same adapter has to
 * be handed to both the run and the resume for the two rounds to line up.
 */
final class ScriptedToolAdapter implements ProviderInterface, ToolCapableInterface
{
    private int $toolCallCount = 0;

    /** @var array<string, mixed> Options seen on the last call, for assertions. */
    public array $lastOptions = [];

    /**
     * @param array<string, mixed> $toolArguments the arguments of the scripted call
     */
    public function __construct(
        private readonly string $finalContent = 'Here are your recent logs.',
        private readonly string $toolName = 'fetch_logs',
        private readonly array $toolArguments = ['limit' => 5],
    ) {}

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $this->toolCallCount++;
        $this->lastOptions = $options;
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
                toolCalls: [ToolCall::function('call_0', $this->toolName, $this->toolArguments)],
            );
        }

        return new CompletionResponse(
            content: $this->finalContent,
            model: $model,
            usage: new UsageStatistics(5, 4, 9),
            finishReason: 'stop',
            provider: 'scripted-fake',
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
