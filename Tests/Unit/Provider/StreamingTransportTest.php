<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Closure;
use Netresearch\NrLlm\Domain\Enum\FailureClass;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Provider\Middleware\FailureClassifier;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Provider\StreamedResponseBody;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Exception\RequestCancelledException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Http\StreamingHttpClientInterface;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

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
#[CoversClass(StreamedResponseBody::class)]
final class StreamingTransportTest extends AbstractUnitTestCase
{
    /** @var list<string> */
    private const OPENAI_PIECES = [
        "data: {\"choices\":[{\"delta\":{\"content\":\"One \"}}]}\n\n",
        "data: {\"choices\":[{\"delta\":{\"content\":\"two \"}}]}\n\n",
        "data: {\"choices\":[{\"delta\":{\"content\":\"three\"}}]}\n\n",
        "data: [DONE]\n\n",
    ];

    private const MESSAGES = [['role' => 'user', 'content' => 'hi']];

    /** Upstream pieces handed out so far — what the provider has "written". */
    private int $produced = 0;

    #[Test]
    public function theFirstChunkArrivesBeforeTheUpstreamHasWrittenTheLast(): void
    {
        $client   = $this->vaultClient(supportsStreaming: true, pieces: self::OPENAI_PIECES);
        $provider = $this->provider(OpenAiProvider::class, $client);

        $chunks = $provider->streamChatCompletion(self::MESSAGES);

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

        $all = [];
        foreach ($this->provider(OpenAiProvider::class, $client)->streamChatCompletion(self::MESSAGES) as $chunk) {
            $all[] = $chunk;
        }

        self::assertSame(['One ', 'two ', 'three'], $all);
    }

    /**
     * @param class-string<AbstractProvider&StreamingCapableInterface> $providerClass
     */
    #[Test]
    #[DataProvider('streamingProviders')]
    public function everyStreamingAdapterSendsThroughSendStreaming(string $providerClass, bool $withApiKey): void
    {
        $client = $this->vaultClient(supportsStreaming: true, pieces: []);

        foreach ($this->provider($providerClass, $client, $withApiKey)->streamChatCompletion(self::MESSAGES) as $ignored) {
            // An empty body: only the transport is under test here.
        }
    }

    /**
     * @return iterable<string, array{class-string<AbstractProvider&StreamingCapableInterface>, bool}>
     */
    public static function streamingProviders(): iterable
    {
        yield 'Claude' => [ClaudeProvider::class, true];
        yield 'Gemini' => [GeminiProvider::class, true];
        yield 'Groq' => [GroqProvider::class, true];
        yield 'Mistral' => [MistralProvider::class, true];
        yield 'Ollama' => [OllamaProvider::class, true];
        yield 'Ollama without an API key' => [OllamaProvider::class, false];
        yield 'OpenAI' => [OpenAiProvider::class, true];
        yield 'OpenRouter' => [OpenRouterProvider::class, true];
    }

    #[Test]
    public function theStreamingSendCarriesThePerRequestAuditReason(): void
    {
        $reasons = [];
        $client  = $this->vaultClient(supportsStreaming: true, pieces: [], reasons: $reasons);

        foreach ($this->provider(OpenAiProvider::class, $client)->streamChatCompletion(self::MESSAGES) as $ignored) {
            // Drained for the send.
        }

        self::assertSame(['LLM chat call to OpenAI (test-model)'], $reasons);
    }

    #[Test]
    public function aKeylessStreamingSendIsUnauthenticatedAndBoundedByTheTimeout(): void
    {
        $reasons  = [];
        $timeouts = [];
        $client   = $this->vaultClient(supportsStreaming: true, pieces: [], reasons: $reasons, keyless: true, timeouts: $timeouts);

        foreach ($this->provider(OllamaProvider::class, $client, withApiKey: false)->streamChatCompletion(self::MESSAGES) as $ignored) {
            // Drained for the send.
        }

        self::assertSame([1], $timeouts);
    }

    /**
     * The keyless path leaves the factory client, so the provider's own host
     * gate must still run before nr-vault's client is used.
     */
    #[Test]
    public function aKeylessStreamingSendToARefusedHostIsStoppedByTheEndpointGate(): void
    {
        $client = $this->vaultClient(supportsStreaming: true, pieces: [], keyless: true, expectStreamingSend: false);

        $e = $this->failureOf(fn (): mixed => $this->provider(OllamaProvider::class, $client, withApiKey: false, baseUrl: 'http://169.254.169.254:11434')
            ->streamChatCompletion(self::MESSAGES)->current());

        self::assertInstanceOf(ProviderConfigurationException::class, $e);
        self::assertSame(1751452800, $e->getCode());
    }

    /**
     * A timeout or reset while the stream is still silent (Claude's ping, a
     * reasoning model) must leave the streaming fallback chain free to try the
     * next configuration, as the connection error of the blocking send did.
     */
    #[Test]
    public function aReadFailureBeforeTheFirstChunkIsAConnectionFailure(): void
    {
        $failure = new VaultException('Streaming transfer failed after the headers arrived; the body is incomplete', 1790475706);
        $client  = $this->vaultClient(supportsStreaming: true, pieces: [], bodyFailure: $failure);

        $e = $this->failureOf(fn (): mixed => $this->provider(OpenAiProvider::class, $client)->streamChatCompletion(self::MESSAGES)->current());

        self::assertInstanceOf(ProviderConnectionException::class, $e);
        self::assertSame(FailureClass::CONNECTION, FailureClassifier::classify($e));
        self::assertSame($failure, $e->getPrevious());
    }

    #[Test]
    public function aCancellationWhileReadingPassesThroughUnchanged(): void
    {
        $cancelled = new RequestCancelledException('cancelled', 1790475704);
        $client    = $this->vaultClient(supportsStreaming: true, pieces: [], bodyFailure: $cancelled);

        $e = $this->failureOf(fn (): mixed => $this->provider(OpenAiProvider::class, $client)->streamChatCompletion(self::MESSAGES)->current());

        self::assertSame($cancelled, $e);
    }

    #[Test]
    #[DataProvider('transferFailureCodes')]
    public function aTransferFailureBeforeTheFirstBytesIsAConnectionFailure(int $code): void
    {
        $failure = new VaultException('Streaming transfer failed before its first bytes', $code);
        $client  = $this->vaultClient(supportsStreaming: true, pieces: [], sendFailure: $failure);

        $e = $this->failureOf(fn (): mixed => $this->provider(OpenAiProvider::class, $client)->streamChatCompletion(self::MESSAGES)->current());

        self::assertInstanceOf(ProviderConnectionException::class, $e);
        self::assertSame(FailureClass::CONNECTION, FailureClassifier::classify($e));
        self::assertSame($failure, $e->getPrevious());
    }

    /**
     * nr-vault 1.1.0 `VaultHttpClient::sendStreamingly()`: total time bound,
     * idle bound, settled rejection, no response, buffer bound.
     *
     * @return iterable<string, array{int}>
     */
    public static function transferFailureCodes(): iterable
    {
        yield 'total time bound' => [1790475709];
        yield 'idle bound' => [1790487201];
        yield 'settled rejection' => [1790475710];
        yield 'no response' => [1790475711];
        yield 'buffer bound' => [1790487102];
    }

    /**
     * A refusal by the host or scheme filter is thrown by the blocking send
     * too; it is not a transfer failure and keeps its class.
     */
    #[Test]
    #[DataProvider('refusalCodes')]
    public function aRefusedRequestKeepsItsVaultException(int $code): void
    {
        $refusal = new VaultException('Request refused by the vault HTTP client', $code);
        $client  = $this->vaultClient(supportsStreaming: true, pieces: [], sendFailure: $refusal);

        $e = $this->failureOf(fn (): mixed => $this->provider(OpenAiProvider::class, $client)->streamChatCompletion(self::MESSAGES)->current());

        self::assertSame($refusal, $e);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusalCodes(): iterable
    {
        yield 'host not allowed' => [1735858522];
        yield 'scheme not allowed' => [1735858523];
    }

    /**
     * The status decides the exception class. A 401 whose error body is cut
     * off must still surface as an authentication failure, not as a
     * retryable connection failure that moves on to the next configuration.
     *
     * @param class-string<ProviderResponseException> $expected
     */
    #[Test]
    #[DataProvider('clientErrorStatuses')]
    public function aClientErrorWhoseBodyFailsToArriveKeepsItsTypedException(int $status, string $expected): void
    {
        $failure = new VaultException('Streaming transfer failed after the headers arrived; the body is incomplete', 1790475706);
        $client  = $this->vaultClient(supportsStreaming: true, pieces: [], bodyFailure: $failure, status: $status);

        $e = $this->failureOf(fn (): mixed => $this->provider(OpenAiProvider::class, $client)->streamChatCompletion(self::MESSAGES)->current());

        self::assertSame($expected, $e::class);
        self::assertNotSame(FailureClass::CONNECTION, FailureClassifier::classify($e));
    }

    /**
     * @return iterable<string, array{int, class-string<ProviderResponseException>}>
     */
    public static function clientErrorStatuses(): iterable
    {
        yield '401' => [401, ProviderAuthenticationException::class];
        yield '429' => [429, ProviderRateLimitException::class];
        yield '400' => [400, ProviderResponseException::class];
    }

    #[Test]
    public function getContentsReportsATransferFailureWithASanitisedMessage(): void
    {
        $inner = self::createStub(StreamInterface::class);
        $inner->method('getContents')->willThrowException(new VaultException('failed at https://llm.example.com/?key=sk-secret', 1790475706));

        $body = new StreamedResponseBody($inner, static fn (string $m): string => str_replace('sk-secret', '***', $m));

        $e = $this->failureOf(static fn (): string => $body->getContents());

        self::assertInstanceOf(ProviderConnectionException::class, $e);
        self::assertStringContainsString('key=***', $e->getMessage());
        self::assertStringNotContainsString('sk-secret', $e->getMessage());
    }

    /**
     * @param Closure(): mixed $call
     */
    private function failureOf(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }

        self::fail('Expected an exception');
    }

    /**
     * @param class-string<AbstractProvider&StreamingCapableInterface> $providerClass
     */
    private function provider(
        string $providerClass,
        VaultHttpClientInterface $client,
        bool $withApiKey = true,
        string $baseUrl = 'https://llm.example.com/v1',
    ): AbstractProvider&StreamingCapableInterface {
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn($client);

        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $vault,
            $this->createPublicDnsHttpClientFactory(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $withApiKey ? $this->randomApiKey() : '',
            'defaultModel'     => 'test-model',
            'baseUrl'          => $baseUrl,
            // Bounds a send that reaches the network by mistake.
            'timeout' => 1,
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
     * @param list<string> $reasons  receives every audit reason stamped on the client
     * @param list<int>    $timeouts receives every timeout set on the client
     * @param bool         $keyless  the client must never be given a secret
     *
     * @return VaultHttpClientInterface&StreamingHttpClientInterface&MockObject
     */
    private function vaultClient(
        bool $supportsStreaming,
        array $pieces,
        ?Throwable $bodyFailure = null,
        ?Throwable $sendFailure = null,
        int $status = 200,
        array &$reasons = [],
        bool $keyless = false,
        array &$timeouts = [],
        bool $expectStreamingSend = true,
    ): MockObject {
        $client = $this->createMockForIntersectionOfInterfaces([
            VaultHttpClientInterface::class,
            StreamingHttpClientInterface::class,
        ]);
        if ($keyless) {
            $client->expects(self::never())->method('withAuthentication');
        } else {
            $client->method('withAuthentication')->willReturnSelf();
        }
        $client->method('withReason')->willReturnCallback(
            static function (string $reason) use ($client, &$reasons): VaultHttpClientInterface {
                $reasons[] = $reason;

                return $client;
            },
        );
        $client->method('withTimeout')->willReturnCallback(
            static function (int $timeout) use ($client, &$timeouts): VaultHttpClientInterface {
                $timeouts[] = $timeout;

                return $client;
            },
        );
        $client->method('supportsStreaming')->willReturn($supportsStreaming);

        $sendStreaming = $client->expects($supportsStreaming && $expectStreamingSend ? self::once() : self::never())->method('sendStreaming');
        if ($sendFailure instanceof Throwable) {
            $sendStreaming->willThrowException($sendFailure);
        } else {
            $sendStreaming->willReturnCallback(
                fn (): ResponseInterface => $this->response($this->lazyBody($pieces, failure: $bodyFailure), $status),
            );
        }

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
    private function lazyBody(array $pieces, bool $countsAsUpstream = true, ?Throwable $failure = null): StreamInterface
    {
        $next = 0;
        $body = self::createStub(StreamInterface::class);
        $body->method('eof')->willReturnCallback(static function () use (&$next, $pieces, $failure): bool {
            return !$failure instanceof Throwable && $next >= count($pieces);
        });
        $body->method('read')->willReturnCallback(function () use (&$next, $pieces, $countsAsUpstream, $failure): string {
            if ($failure instanceof Throwable) {
                throw $failure;
            }

            if ($next >= count($pieces)) {
                return '';
            }

            if ($countsAsUpstream) {
                ++$this->produced;
            }

            return $pieces[$next++];
        });
        if ($failure instanceof Throwable) {
            $body->method('getContents')->willThrowException($failure);
            $body->method('__toString')->willThrowException($failure);
        }

        return $body;
    }

    private function response(StreamInterface $body, int $status = 200): ResponseInterface
    {
        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        $response->method('withBody')->willReturnCallback(
            fn (StreamInterface $replaced): ResponseInterface => $this->response($replaced, $status),
        );

        return $response;
    }
}
