<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

/**
 * The four adapters speaking OpenAI's chat-completions dialect — OpenAI,
 * Groq, Mistral, OpenRouter — share their wire fixtures and their
 * structured-output assertions. Everything they do NOT share stays in the
 * concrete case, which is where a per-adapter deviation has to be declared.
 */
abstract class AbstractOpenAiDialectContractTestCase extends AbstractAdapterContractTestCase
{
    protected function chatResponseBody(int $promptTokens, int $completionTokens, string $content = 'ok'): array
    {
        return [
            'id' => 'chatcmpl-contract',
            'object' => 'chat.completion',
            'created' => 1_760_000_000,
            'model' => $this->wireModelId(),
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
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
            'id' => 'chatcmpl-contract-tools',
            'object' => 'chat.completion',
            'created' => 1_760_000_000,
            'model' => $this->wireModelId(),
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_contract_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"city":"Leipzig"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
        ];
    }

    /**
     * The strict rung of ADR-128: `response_format.json_schema` with
     * `strict: true` and the schema verbatim, so the API enforces it.
     */
    protected function assertSchemaEnforcedOnTheWire(array $payload, array $schema): void
    {
        self::assertArrayHasKey('response_format', $payload);
        $format = $payload['response_format'];
        self::assertIsArray($format);
        self::assertSame('json_schema', $format['type'] ?? null);

        $jsonSchema = $format['json_schema'] ?? null;
        self::assertIsArray($jsonSchema);
        self::assertTrue($jsonSchema['strict'] ?? null);
        self::assertSame($schema, $jsonSchema['schema'] ?? null);
    }

    /**
     * The degradation rung: JSON mode. Strict mode answers a schema outside
     * its rules with an HTTP 400, so a valid ADR-126 schema must not be sent
     * as strict — the prompt instruction plus the local validation carry it.
     */
    protected function assertUnsupportedSchemaDegrades(array $payload, array $schema): void
    {
        self::assertSame(['type' => 'json_object'], $payload['response_format'] ?? null);
    }

    /**
     * The model id the provider echoes back in its response.
     */
    abstract protected function wireModelId(): string;
}
