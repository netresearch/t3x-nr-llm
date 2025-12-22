<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

interface StreamingCapableInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return \Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): \Generator;

    public function supportsStreaming(): bool;
}
