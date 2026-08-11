<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

use Netresearch\NrLlm\Provider\OllamaProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(OllamaProvider::class)]
final class OllamaAdapterContractTest extends AbstractAdapterContractTestCase
{
    private const MODEL = 'llama3.2';

    protected function newAdapter(): OllamaProvider
    {
        return new OllamaProvider(
            $this->requestFactory,
            $this->streamFactory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
    }

    protected function expectedIdentifier(): string
    {
        return 'ollama';
    }

    /**
     * Ollama is the one adapter with no API key: the empty identifier is the
     * configuration, not an omission.
     */
    protected function adapterConfiguration(): array
    {
        return [
            'apiKeyIdentifier' => '',
            'defaultModel' => self::MODEL,
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ];
    }

    /**
     * DEVIATION: Ollama runs locally and authenticates nothing, so
     * `validateConfiguration()` is overridden to a base-URL default instead of
     * a credential check. "Refuse without a credential" has nothing to refuse.
     */
    protected function requiresApiKey(): bool
    {
        return false;
    }

    protected function chatResponseBody(int $promptTokens, int $completionTokens, string $content = 'ok'): array
    {
        return [
            'model' => self::MODEL,
            'created_at' => '2026-08-11T10:00:00Z',
            'message' => ['role' => 'assistant', 'content' => $content],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => $promptTokens,
            'eval_count' => $completionTokens,
        ];
    }

    protected function chatResponseBodyWithoutUsage(): array
    {
        $body = $this->chatResponseBody(0, 0);
        unset($body['prompt_eval_count'], $body['eval_count']);

        return $body;
    }

    protected function toolCallResponseBody(): array
    {
        return [
            'model' => self::MODEL,
            'created_at' => '2026-08-11T10:00:00Z',
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => ['city' => 'Leipzig'],
                        ],
                    ],
                ],
            ],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 12,
            'eval_count' => 8,
        ];
    }

    /**
     * Ollama takes the JSON Schema verbatim in the top-level `format` field
     * and constrains generation against it.
     */
    protected function assertSchemaEnforcedOnTheWire(array $payload, array $schema): void
    {
        self::assertSame($schema, $payload['format'] ?? null);
    }

    protected function assertUnsupportedSchemaDegrades(array $payload, array $schema): void
    {
        self::markTestSkipped(
            'OllamaProvider has no strict/loose rungs to fall between: `format` carries the schema verbatim, '
            . 'so there is no narrower profile a schema could fail to qualify for.',
        );
    }
}
