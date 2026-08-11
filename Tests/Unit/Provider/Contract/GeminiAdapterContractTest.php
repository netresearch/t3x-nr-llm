<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

use Netresearch\NrLlm\Provider\GeminiProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(GeminiProvider::class)]
final class GeminiAdapterContractTest extends AbstractAdapterContractTestCase
{
    private const MODEL = 'gemini-2.5-flash';

    protected function newAdapter(): GeminiProvider
    {
        return new GeminiProvider(
            $this->requestFactory,
            $this->streamFactory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
    }

    protected function expectedIdentifier(): string
    {
        return 'gemini';
    }

    protected function adapterConfiguration(): array
    {
        return [
            'apiKeyIdentifier' => 'vault-gemini',
            'defaultModel' => self::MODEL,
            'timeout' => 30,
        ];
    }

    protected function chatResponseBody(int $promptTokens, int $completionTokens, string $content = 'ok'): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [['text' => $content]],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => $promptTokens,
                'candidatesTokenCount' => $completionTokens,
                'totalTokenCount' => $promptTokens + $completionTokens,
            ],
            'modelVersion' => self::MODEL,
        ];
    }

    protected function chatResponseBodyWithoutUsage(): array
    {
        $body = $this->chatResponseBody(0, 0);
        unset($body['usageMetadata']);

        return $body;
    }

    protected function toolCallResponseBody(): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['city' => 'Leipzig'],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 12,
                'candidatesTokenCount' => 8,
                'totalTokenCount' => 20,
            ],
            'modelVersion' => self::MODEL,
        ];
    }

    /**
     * Gemini enforces through `generationConfig.responseSchema` in its own
     * OpenAPI-flavoured dialect — types uppercased, keywords it does not
     * document dropped.
     */
    protected function assertSchemaEnforcedOnTheWire(array $payload, array $schema): void
    {
        $config = $payload['generationConfig'] ?? null;
        self::assertIsArray($config);
        self::assertSame('application/json', $config['responseMimeType'] ?? null);

        $responseSchema = $config['responseSchema'] ?? null;
        self::assertIsArray($responseSchema);
        self::assertSame('OBJECT', $responseSchema['type'] ?? null);

        $properties = $responseSchema['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertIsArray($properties['title'] ?? null);
        self::assertSame('STRING', $properties['title']['type'] ?? null);
    }

    /**
     * Gemini's degradation is per keyword rather than per request: a keyword
     * the dialect cannot express is dropped, which only ever WIDENS what the
     * model may emit. The local ADR-126 validation stays authoritative, so
     * widening is safe — silently narrowing would not be.
     */
    protected function assertUnsupportedSchemaDegrades(array $payload, array $schema): void
    {
        $config = $payload['generationConfig'] ?? null;
        self::assertIsArray($config);
        self::assertSame('application/json', $config['responseMimeType'] ?? null);

        $responseSchema = $config['responseSchema'] ?? null;
        self::assertIsArray($responseSchema);
        $properties = $responseSchema['properties'] ?? null;
        self::assertIsArray($properties);
        $title = $properties['title'] ?? null;
        self::assertIsArray($title);

        self::assertArrayNotHasKey(
            'minLength',
            $title,
            'A keyword Gemini does not document must be dropped, not forwarded.',
        );
        self::assertSame('STRING', $title['type'] ?? null);
    }
}
