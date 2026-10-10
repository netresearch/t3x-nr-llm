<?php

/* Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiCallMetadata;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use stdClass;

#[CoversClass(GeminiProvider::class)]
final class GeminiStructBoundaryTest extends AbstractUnitTestCase
{
    /**
     * @param array<array-key,mixed> $expected
     */
    #[Test]
    #[DataProvider('argumentRoots')]
    public function actualResponseToNextRequestKeepsKnownArgumentObject(
        string $transport,
        bool $owned,
        string $arguments,
        array $expected,
    ): void {
        $raw = '{"candidates":[{"content":{"role":"model","parts":[{"text":"Before."},{"functionCall":{"name":"struct_probe","args":' . $arguments . '},"thoughtSignature":"opaque+/signature=="},{"text":"After."}]},"finishReason":"STOP"}]}';
        $requests = [];
        $subject = $this->provider([$raw, $this->finished($transport)], $requests);
        $tools = [ToolSpec::function('struct_probe', 'Probe.', ['type' => 'object'])];
        $messages = [ChatMessage::user('Literal question.')];
        $first = $subject->chatCompletionWithTools(
            $messages,
            $tools,
            ['_capture_raw' => true],
        );
        self::assertNotNull($first->toolCalls);
        self::assertCount(1, $first->toolCalls);
        self::assertSame('struct_probe', $first->toolCalls[0]->name);
        self::assertSame($expected, $first->toolCalls[0]->arguments);
        self::assertNotNull($first->metadata);
        self::assertSame(
            json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
            $first->metadata['_raw'] ?? null,
        );
        $items = $this->items($first);
        $before = $items;
        $messages[] = ChatMessage::assistantToolCalls(
            $first->toolCalls,
            $first->content,
            $owned ? $items : null,
        );
        $messages[] = ChatMessage::toolResult($first->toolCalls[0]->id, '{}');
        $this->invoke($subject, $transport, $messages, $tools);
        self::assertCount(2, $requests);
        $parts = $this->requestParts($requests[1], 1);
        $call = $parts[1]->functionCall ?? null;
        self::assertInstanceOf(stdClass::class, $call);
        self::assertSame('struct_probe', $call->name);
        self::assertInstanceOf(
            stdClass::class,
            $call->args,
            'FunctionCall.args is a known Struct object on the actual wire.',
        );
        self::assertSame($expected, get_object_vars($call->args));
        if ($owned) {
            self::assertCount(3, $parts);
            self::assertSame('Before.', $parts[0]->text);
            self::assertSame('After.', $parts[2]->text);
            self::assertSame('opaque+/signature==', $parts[1]->thoughtSignature);
        }

        self::assertSame(
            $before,
            $items,
            'Final wire construction must not rewrite the carrier.',
        );
        self::assertSame($before, $owned ? $messages[1]->providerItems : $items);
    }

    /**
     * @return iterable<string,array{string,bool,string,array<array-key,mixed>}>
     */
    public static function argumentRoots(): iterable
    {
        foreach (['tools', 'chat', 'stream'] as $transport) {
            foreach ([true, false] as $owned) {
                foreach ([
                    'empty' => ['{}', []],
                    'numeric' => ['{"0":"zero","1":"one"}', [0 => 'zero', 1 => 'one']],
                    'ordinary with real nested list' => [
                        '{"label":"x","items":[]}',
                        ['label' => 'x', 'items' => []],
                    ],
                ] as $name => [$json, $expected]) {
                    yield $transport . ($owned ? ' owned ' : ' generic ') . $name => [$transport, $owned, $json, $expected];
                }
            }
        }
    }

    /**
     * @param array<array-key,mixed> $expected
     */
    #[Test]
    #[DataProvider('resultRoots')]
    public function actualResultRequestKeepsKnownResponseObject(
        string $transport,
        string $result,
        array $expected,
    ): void {
        $requests = [];
        $subject = $this->provider([$this->finished($transport)], $requests);
        $messages = [
            ChatMessage::user('Literal question.'),
            ChatMessage::assistantToolCalls(
                [ToolCall::function('fixture-call', 'struct_probe', [])],
            ),
            ChatMessage::toolResult('fixture-call', $result),
        ];
        $this->invoke($subject, $transport, $messages, []);
        self::assertCount(1, $requests);
        $parts = $this->requestParts($requests[0], 2);
        $resultPart = $parts[0]->functionResponse ?? null;
        self::assertInstanceOf(stdClass::class, $resultPart);
        self::assertSame('struct_probe', $resultPart->name);
        self::assertInstanceOf(
            stdClass::class,
            $resultPart->response,
            'FunctionResponse.response is a known Struct object on the actual wire.',
        );
        self::assertSame($expected, get_object_vars($resultPart->response));
    }

    /**
     * @return iterable<string,array{string,string,array<array-key,mixed>}>
     */
    public static function resultRoots(): iterable
    {
        foreach (['tools', 'chat', 'stream'] as $transport) {
            foreach ([
                'empty' => ['{}', []],
                'numeric' => ['{"0":"zero","1":"one"}', [0 => 'zero', 1 => 'one']],
                'list root' => ['["zero","one"]', [0 => 'zero', 1 => 'one']],
                'ordinary nested list' => ['{"items":[]}', ['items' => []]],
                'scalar' => ['7', ['result' => '7']],
                'string scalar' => ['"value"', ['result' => '"value"']],
                'invalid' => ['not json', ['result' => 'not json']],
            ] as $name => [$json, $expected]) {
                yield $transport . ' ' . $name => [$transport, $json, $expected];
            }
        }
    }

    /**
     * @param list<ChatMessage> $messages
     * @param list<ToolSpec>    $tools
     */
    private function invoke(
        GeminiProvider $subject,
        string $transport,
        array $messages,
        array $tools,
    ): void {
        if ($transport === 'stream') {
            self::assertSame(
                ['Done.'],
                iterator_to_array(
                    $subject->streamChatCompletion($messages),
                    false,
                ),
            );
        } elseif ($transport === 'chat') {
            self::assertSame(
                'Done.',
                $subject->chatCompletion($messages)->content,
            );
        } else {
            self::assertSame(
                'Done.',
                $subject->chatCompletionWithTools($messages, $tools)->content,
            );
        }
    }

    private function finished(string $transport): string
    {
        $body = '{"candidates":[{"content":{"role":"model","parts":[{"text":"Done."}]},"finishReason":"STOP"}]}';
        return $transport === 'stream' ? 'data: ' . $body . "\n\n" : $body;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function items(CompletionResponse $response): array
    {
        $items = $response->metadata[OpenAiCallMetadata::KEY_PROVIDER_ITEMS] ?? null;
        self::assertIsArray($items);
        self::assertCount(1, $items);
        $item = $items[0] ?? null;
        self::assertIsArray($item);
        $parts = $item['parts'] ?? null;
        self::assertIsArray($parts);
        $validated = ['type' => 'nrllm_gemini_generate_content', 'parts' => $parts];
        self::assertSame([$validated], $items);
        return [$validated];
    }

    /**
     * @param list<string>           $responses
     * @param list<RequestInterface> $requests
     */
    private function provider(
        array $responses,
        array &$requests,
    ): GeminiProvider {
        $client = self::createMock(ClientInterface::class);
        $index = 0;
        $client
            ->expects(self::exactly(count($responses)))
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use (&$requests, &$index, $responses): ResponseInterface {
                    $requests[] = $request;
                    return new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        $responses[$index++],
                    );
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
                'defaultModel' => 'gemini-struct-fixture',
                'maxRetries' => 0,
            ],
        );
        $subject->setHttpClient($client);
        return $subject;
    }

    /**
     * @return list<stdClass>
     */
    private function requestParts(RequestInterface $request, int $turn): array
    {
        $wire = json_decode(
            (string)$request->getBody(),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $wire);
        $contents = $wire->contents ?? null;
        self::assertIsArray($contents);
        $content = $contents[$turn] ?? null;
        self::assertInstanceOf(stdClass::class, $content);
        $parts = $content->parts ?? null;
        self::assertIsArray($parts);
        $validated = [];
        foreach ($parts as $part) {
            self::assertInstanceOf(stdClass::class, $part);
            $validated[] = $part;
        }

        return $validated;
    }
}
