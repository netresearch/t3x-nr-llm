<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;

interface ToolCapableInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<int, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $tools
     * @param array<string, mixed> $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse;

    public function supportsTools(): bool;
}
