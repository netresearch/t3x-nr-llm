<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(GeminiProvider::class)]
final class GeminiNativeTextTest extends AbstractUnitTestCase
{
    /**
     * @param list<mixed> $parts
     */
    #[Test]
    #[DataProvider('nativePartCases')]
    public function synchronousChatSeparatesNativeAndInlineThinking(
        bool $tools,
        array $parts,
        string $expectedContent,
        ?string $expectedThinking,
    ): void {
        $body = [
            'candidates' => [
                [
                    'content' => ['role' => 'model', 'parts' => $parts],
                    'finishReason' => 'MAX_TOKENS',
                ],
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [['text' => 'Unused second candidate.']],
                    ],
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 11],
        ];
        $requests = [];
        $subject = $this->provider($body, $requests);
        $messages = [ChatMessage::user('Give the visible answer.')];
        $response = $tools ? $subject->chatCompletionWithTools(
            $messages,
            [
                ToolSpec::function(
                    'unused_probe',
                    'Unused.',
                    ['type' => 'object'],
                ),
            ],
            ['model' => 'gemini-text-fixture'],
        ) : $subject->chatCompletion($messages, ['model' => 'gemini-text-fixture']);

        self::assertSame($expectedContent, $response->content);
        self::assertSame($expectedThinking, $response->thinking);
        self::assertCount(1, $requests);
        self::assertSame('gemini-text-fixture', $response->model);
        self::assertSame('gemini', $response->provider);
        self::assertSame('length', $response->finishReason);
        self::assertSame(7, $response->usage->promptTokens);
        self::assertSame(11, $response->usage->completionTokens);
        self::assertNull($response->toolCalls);
        self::assertNull($response->metadata);
    }

    /**
     * @return iterable<string,array{bool,list<mixed>,string,?string}>
     */
    public static function nativePartCases(): iterable
    {
        $cases = [
            'ordinary whitespace remains exact' => [[['text' => "  Visible\tanswer.\n"]], "  Visible\tanswer.\n", null],
            'all visible parts concatenate in order' => [
                [['text' => 'One '], ['text' => 'two'], ['text' => '.']],
                'One two.',
                null,
            ],
            'native thought first' => [
                [
                    ['text' => 'Reason.', 'thought' => true],
                    ['text' => 'Answer.'],
                ],
                'Answer.',
                'Reason.',
            ],
            'native thought middle' => [
                [
                    ['text' => 'One '],
                    ['text' => 'Reason.', 'thought' => true],
                    ['text' => 'two.'],
                ],
                'One two.',
                'Reason.',
            ],
            'native thought last' => [
                [
                    ['text' => 'Answer.'],
                    ['text' => 'Reason.', 'thought' => true],
                ],
                'Answer.',
                'Reason.',
            ],
            'all native thoughts concatenate without invented separator' => [
                [
                    ['text' => 'Reason ', 'thought' => true],
                    ['text' => 'Answer.'],
                    ['text' => 'continued.', 'thought' => true],
                ],
                'Answer.',
                'Reason continued.',
            ],
            'thought only has empty visible content' => [[['text' => 'Reason.', 'thought' => true]], '', 'Reason.'],
            'empty native contribution remains absent' => [
                [['text' => '', 'thought' => true], ['text' => 'Answer.']],
                'Answer.',
                null,
            ],
            'native whitespace is retained verbatim' => [
                [['text' => " \t\n", 'thought' => true], ['text' => 'Answer.']],
                'Answer.',
                " \t\n",
            ],
            'false thought marker is visible' => [[['text' => 'Visible.', 'thought' => false]], 'Visible.', null],
            'integer thought marker is visible' => [[['text' => 'Visible.', 'thought' => 1]], 'Visible.', null],
            'string thought marker is visible' => [[['text' => 'Visible.', 'thought' => 'true']], 'Visible.', null],
            'inline thinking preserves existing normalization' => [
                [['text' => "  foo<think> inline </think>\tbar  "]],
                'foo bar',
                'inline',
            ],
            'inline delimiter may span visible parts' => [
                [
                    ['text' => 'foo<th'],
                    ['text' => 'ink>split'],
                    ['text' => '</think>bar'],
                ],
                'foo bar',
                'split',
            ],
            'two inline blocks retain one LF' => [
                [['text' => 'A<think> first </think>B<think> second </think>C']],
                'A B C',
                "first \n second",
            ],
            'native and inline contributions use one LF' => [
                [
                    ['text' => 'Native.', 'thought' => true],
                    ['text' => 'foo<think> inline </think>bar'],
                ],
                'foo bar',
                "Native.\ninline",
            ],
            'native tags are not parsed again' => [
                [
                    ['text' => '<think>Native.</think>', 'thought' => true],
                    ['text' => 'Answer.'],
                ],
                'Answer.',
                '<think>Native.</think>',
            ],
            'empty inline plus native adds no separator' => [
                [
                    ['text' => 'Native.', 'thought' => true],
                    ['text' => 'foo<think> </think>bar'],
                ],
                'foo bar',
                'Native.',
            ],
            'invalid nontext parts are skipped' => [
                [
                    null,
                    42,
                    ['text' => ['nested']],
                    ['text' => 7],
                    [
                        'inlineData' => ['mimeType' => 'image/png', 'data' => 'fixture'],
                    ],
                    ['text' => 'Answer.'],
                ],
                'Answer.',
                null,
            ],
            'empty candidate does not consume another candidate' => [[], '', null],
        ];
        foreach ([false, true] as $tools) {
            foreach ($cases as $name => [$parts, $content, $thinking]) {
                yield ($tools ? 'tools: ' : 'chat: ') . $name => [$tools, $parts, $content, $thinking];
            }
        }
    }

    #[Test]
    public function toolCallsAndCapturedRawPartsRemainIndependentOfTextExtraction(): void
    {
        $parts = [
            [
                'text' => 'Native.',
                'thought' => true,
                'thoughtSignature' => 'opaque-native-signature',
            ],
            ['text' => 'Answer.'],
            [
                'functionCall' => ['name' => 'lookup_probe', 'args' => ['zone' => 'UTC']],
                'thoughtSignature' => 'opaque-call-signature',
            ],
        ];
        $body = [
            'candidates' => [
                [
                    'content' => ['role' => 'model', 'parts' => $parts],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 5],
        ];
        $requests = [];
        $subject = $this->provider($body, $requests);
        $response = $subject->chatCompletionWithTools(
            [ChatMessage::user('Use the lookup probe.')],
            [
                ToolSpec::function(
                    'lookup_probe',
                    'Lookup.',
                    [
                        'type' => 'object',
                        'properties' => ['zone' => ['type' => 'string']],
                    ],
                ),
            ],
            ['_capture_raw' => true],
        );
        self::assertSame('Answer.', $response->content);
        self::assertSame('Native.', $response->thinking);
        self::assertNotNull($response->toolCalls);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('lookup_probe', $response->toolCalls[0]->name);
        self::assertSame(['zone' => 'UTC'], $response->toolCalls[0]->arguments);
        self::assertStringStartsWith('call_', $response->toolCalls[0]->id);
        self::assertNotNull($response->metadata);
        self::assertSame($body, $response->metadata['_raw'] ?? null);
        self::assertSame('stop', $response->finishReason);
        self::assertCount(1, $requests);
    }

    /**
     * @param array<string,mixed>    $body
     * @param list<RequestInterface> $requests
     */
    private function provider(array $body, array &$requests): GeminiProvider
    {
        $reply = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $client = self::createMock(ClientInterface::class);
        $client
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use (&$requests, $reply): ResponseInterface {
                    $requests[] = $request;
                    return $reply;
                },
            );
        $factory = new HttpFactory();
        $subject = new GeminiProvider(
            $factory,
            $factory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            new SecureHttpClientFactory(),
        );
        $subject->configure(
            [
                'apiKeyIdentifier' => 'fixture-key',
                'defaultModel' => 'gemini-text-fixture',
                'maxRetries' => 0,
            ],
        );
        $subject->setHttpClient($client);
        return $subject;
    }
}
