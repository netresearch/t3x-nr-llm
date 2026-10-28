<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\StreamingHttpClientInterface;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

use function count;
use function implode;

/**
 * A streamed answer must reach the caller while the provider is still writing
 * it.
 *
 * The streaming adapters used to send with PSR-18 `sendRequest()`, which on
 * nr-vault's client returns only after the whole body has arrived, so every
 * chunk reached the generator at the end. The doubles below model that: the
 * blocking `sendRequest()` drains the whole upstream before it returns, while
 * `sendStreaming()` hands out a body that pulls one upstream piece per read.
 */
#[CoversClass(AbstractProvider::class)]
#[CoversClass(OpenAiProvider::class)]
final class StreamingTransportTest extends AbstractUnitTestCase
{
    /** @var list<string> */
    private const OPENAI_PIECES = [
        "data: {\"choices\":[{\"delta\":{\"content\":\"One \"}}]}\n\n",
        "data: {\"choices\":[{\"delta\":{\"content\":\"two \"}}]}\n\n",
        "data: {\"choices\":[{\"delta\":{\"content\":\"three\"}}]}\n\n",
        "data: [DONE]\n\n",
    ];

    /** Upstream pieces handed out so far — what the provider has "written". */
    private int $produced = 0;

    #[Test]
    public function theFirstChunkArrivesBeforeTheUpstreamHasWrittenTheLast(): void
    {
        $client   = $this->vaultClient(supportsStreaming: true, pieces: self::OPENAI_PIECES);
        $provider = $this->provider(OpenAiProvider::class, $client);

        $chunks = $provider->streamChatCompletion([['role' => 'user', 'content' => 'hi']]);

        self::assertSame('One ', $chunks->current());
        self::assertLessThan(
            count(self::OPENAI_PIECES),
            $this->produced,
            'The first chunk was yielded only after the whole upstream answer had been read',
        );

        $rest = [];
        $chunks->next();
        while ($chunks->valid()) {
            $rest[] = $chunks->current();
            $chunks->next();
        }

        self::assertSame(['two ', 'three'], $rest);
        self::assertSame(count(self::OPENAI_PIECES), $this->produced);
    }

    #[Test]
    public function aClientThatCannotStreamStillSendsThroughSendRequest(): void
    {
        $client = $this->vaultClient(supportsStreaming: false, pieces: self::OPENAI_PIECES);

        $chunks = $this->provider(OpenAiProvider::class, $client)
            ->streamChatCompletion([['role' => 'user', 'content' => 'hi']]);

        $all = [];
        foreach ($chunks as $chunk) {
            $all[] = $chunk;
        }

        self::assertSame(['One ', 'two ', 'three'], $all);
    }

    /**
     * @param class-string<AbstractProvider&StreamingCapableInterface> $providerClass
     */
    #[Test]
    #[DataProvider('streamingProviders')]
    public function everyStreamingAdapterSendsThroughSendStreaming(string $providerClass): void
    {
        $client = $this->vaultClient(supportsStreaming: true, pieces: []);

        $chunks = $this->provider($providerClass, $client)
            ->streamChatCompletion([['role' => 'user', 'content' => 'hi']]);

        foreach ($chunks as $ignored) {
            // An empty body: only the transport is under test here.
        }
    }

    /**
     * @return iterable<string, array{class-string<AbstractProvider&StreamingCapableInterface>}>
     */
    public static function streamingProviders(): iterable
    {
        yield 'Claude' => [ClaudeProvider::class];
        yield 'Gemini' => [GeminiProvider::class];
        yield 'Groq' => [GroqProvider::class];
        yield 'Mistral' => [MistralProvider::class];
        yield 'Ollama' => [OllamaProvider::class];
        yield 'OpenAI' => [OpenAiProvider::class];
        yield 'OpenRouter' => [OpenRouterProvider::class];
    }

    /**
     * @param class-string<AbstractProvider&StreamingCapableInterface> $providerClass
     */
    private function provider(string $providerClass, VaultHttpClientInterface $client): AbstractProvider&StreamingCapableInterface
    {
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn($client);

        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $vault,
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel'     => 'test-model',
            'baseUrl'          => 'https://llm.example.com/v1',
        ]);

        return $provider;
    }

    /**
     * A vault client whose `sendStreaming()` body pulls one upstream piece per
     * read, and whose `sendRequest()` reads the whole upstream before it
     * returns — the way the blocking send of the real client does. A client
     * that can stream must send the answer through `sendStreaming()` exactly
     * once; one that cannot must use `sendRequest()` once and never
     * `sendStreaming()`.
     *
     * @param list<string> $pieces
     *
     * @return VaultHttpClientInterface&StreamingHttpClientInterface&MockObject
     */
    private function vaultClient(bool $supportsStreaming, array $pieces): MockObject
    {
        $client = $this->createMockForIntersectionOfInterfaces([
            VaultHttpClientInterface::class,
            StreamingHttpClientInterface::class,
        ]);
        $client->method('withAuthentication')->willReturnSelf();
        $client->method('withReason')->willReturnSelf();
        $client->method('withTimeout')->willReturnSelf();
        $client->method('supportsStreaming')->willReturn($supportsStreaming);
        $client->expects($supportsStreaming ? self::once() : self::never())->method('sendStreaming')->willReturnCallback(
            fn (): ResponseInterface => $this->response($this->lazyBody($pieces)),
        );
        // Not forbidden when the client can stream: an adapter may make other,
        // non-streaming calls first (OpenRouter's routing reads the model list).
        $sendRequest = $supportsStreaming
            ? $client->method('sendRequest')
            : $client->expects(self::once())->method('sendRequest');
        $sendRequest->willReturnCallback(
            function () use ($pieces): ResponseInterface {
                $all  = [];
                $body = $this->lazyBody($pieces);
                while (!$body->eof()) {
                    $all[] = $body->read(8192);
                }

                return $this->response($this->lazyBody([implode('', $all)], countsAsUpstream: false));
            },
        );

        return $client;
    }

    /**
     * @param list<string> $pieces
     */
    private function lazyBody(array $pieces, bool $countsAsUpstream = true): StreamInterface
    {
        $next = 0;
        $body = self::createStub(StreamInterface::class);
        $body->method('eof')->willReturnCallback(static function () use (&$next, $pieces): bool {
            return $next >= count($pieces);
        });
        $body->method('read')->willReturnCallback(function () use (&$next, $pieces, $countsAsUpstream): string {
            if ($next >= count($pieces)) {
                return '';
            }

            if ($countsAsUpstream) {
                ++$this->produced;
            }

            return $pieces[$next++];
        });

        return $body;
    }

    private function response(StreamInterface $body): ResponseInterface
    {
        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($body);

        return $response;
    }
}
