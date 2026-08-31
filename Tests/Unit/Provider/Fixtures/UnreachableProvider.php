<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Fixtures;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;

/**
 * An adapter whose connection test always fails the way a real one does when
 * the endpoint does not answer.
 *
 * It exists so the registry's own behaviour — that a failing adapter becomes a
 * RESULT rather than an exception or a hang — can be asserted without a socket.
 * The functional test that used to prove it measured wall-clock time against a
 * non-routable address, which measures the runner: it passed alone and failed
 * under the four-way sharded run that gates every pull request (#868).
 *
 * The message carries a query-string secret on purpose, so the sanitiser the
 * registry applies has something to strip.
 */
final class UnreachableProvider extends AbstractProvider
{
    public const FAILURE_MESSAGE = 'cURL error 28: Operation timed out for https://provider.example.invalid/v1/models?api_key=SECRET-VALUE';

    public function getName(): string
    {
        return 'Unreachable';
    }

    public function getIdentifier(): string
    {
        return 'unreachable';
    }

    /**
     * Available by construction: the registry refuses an unavailable adapter
     * before it ever calls testConnection(), and that is a different branch
     * from the one under test.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testConnection(): array
    {
        throw new ProviderConnectionException(self::FAILURE_MESSAGE, 1788160001);
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://provider.example.invalid/v1';
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return [];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        throw new ProviderConnectionException(self::FAILURE_MESSAGE, 1788160002);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        return new EmbeddingResponse(
            embeddings: [],
            model: 'none',
            usage: new UsageStatistics(0, 0, 0),
            provider: $this->getIdentifier(),
        );
    }
}
