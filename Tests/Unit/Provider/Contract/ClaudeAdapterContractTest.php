<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

use Netresearch\NrLlm\Provider\ClaudeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(ClaudeProvider::class)]
final class ClaudeAdapterContractTest extends AbstractAdapterContractTestCase
{
    private const MODEL = 'claude-sonnet-4-5-20250929';

    protected function newAdapter(): ClaudeProvider
    {
        return new ClaudeProvider(
            $this->requestFactory,
            $this->streamFactory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
    }

    protected function expectedIdentifier(): string
    {
        return 'claude';
    }

    protected function adapterConfiguration(): array
    {
        return [
            'apiKeyIdentifier' => 'vault-claude',
            'defaultModel' => self::MODEL,
            'timeout' => 30,
        ];
    }

    protected function chatResponseBody(int $promptTokens, int $completionTokens, string $content = 'ok'): array
    {
        return [
            'id' => 'msg_contract',
            'type' => 'message',
            'role' => 'assistant',
            'model' => self::MODEL,
            'content' => [
                ['type' => 'text', 'text' => $content],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => $promptTokens,
                'output_tokens' => $completionTokens,
            ],
        ];
    }

    protected function chatResponseBodyWithoutUsage(): array
    {
        $body = $this->chatResponseBody(0, 0);
        unset($body['usage']);

        return $body;
    }

    protected function toolCallResponseBody(): array
    {
        return [
            'id' => 'msg_contract_tools',
            'type' => 'message',
            'role' => 'assistant',
            'model' => self::MODEL,
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_contract_1',
                    'name' => 'get_weather',
                    'input' => ['city' => 'Leipzig'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
        ];
    }

    /**
     * The Messages API has no `response_format`. Claude's native enforcement
     * is a single forced tool whose `input_schema` IS the schema (ADR-128).
     */
    protected function assertSchemaEnforcedOnTheWire(array $payload, array $schema): void
    {
        $tools = $payload['tools'] ?? null;
        self::assertIsArray($tools);
        self::assertCount(1, $tools);

        $tool = $tools[0] ?? null;
        self::assertIsArray($tool);
        self::assertSame($schema, $tool['input_schema'] ?? null);

        $choice = $payload['tool_choice'] ?? null;
        self::assertIsArray($choice);
        self::assertSame('tool', $choice['type'] ?? null);
        self::assertSame($tool['name'] ?? null, $choice['name'] ?? null);
    }

    protected function assertUnsupportedSchemaDegrades(array $payload, array $schema): void
    {
        self::markTestSkipped(
            'ClaudeProvider has no strict/loose rungs to fall between: `input_schema` accepts full JSON Schema, '
            . 'so the only thing that can disqualify a schema is a non-object root — which ADR-126 already excludes.',
        );
    }
}
