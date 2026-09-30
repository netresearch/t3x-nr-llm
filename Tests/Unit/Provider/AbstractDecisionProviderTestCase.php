<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\AbstractDecisionProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared set-up of the decision provider tests (ADR-211): a provider whose
 * HTTP client answers from a queue and which records every request it sent —
 * method, URL and decoded JSON body — so a test asserts the wire format the
 * API receives, not what the code meant to send.
 */
abstract class AbstractDecisionProviderTestCase extends AbstractUnitTestCase
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>|null, raw: string}> */
    protected array $sent = [];

    /** Requests the HTTP client dispatched — a retry re-sends the same request. */
    protected int $dispatches = 0;

    /**
     * @param class-string<AbstractDecisionProvider> $class
     * @param list<ResponseInterface>                $responses answered in order
     * @param array<string, mixed>                   $config
     */
    protected function provider(string $class, array $responses, array $config = []): AbstractDecisionProvider
    {
        $body = null;
        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturnCallback(
            function (string $method, string $url) use (&$body): RequestInterface {
                $this->sent[] = ['method' => $method, 'url' => $url, 'body' => null, 'raw' => ''];

                return $this->createRequestMock($method, $url, $body);
            },
        );

        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function () use (&$responses, &$body): ResponseInterface {
                ++$this->dispatches;
                $last = array_key_last($this->sent);
                if ($last !== null && is_string($body)) {
                    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    self::assertIsArray($decoded);
                    /** @var array<string, mixed> $decoded */
                    $this->sent[$last] = ['body' => $decoded, 'raw' => $body] + $this->sent[$last];
                    $body = null;
                }

                return array_shift($responses) ?? self::fail('no response queued for this request');
            },
        );

        $provider = new $class(
            $requestFactory,
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        // Retries would sleep between attempts; one request per call keeps
        // a 5xx test fast and its request count exact.
        $provider->configure(['maxRetries' => 0, 'timeout' => 30, ...$config]);
        // After configure(), which resets the client.
        $provider->setHttpClient($client);

        return $provider;
    }

    /**
     * The JSON body of the only request sent.
     *
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        self::assertCount(1, $this->sent);
        $body = $this->sent[0]['body'];
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function ok(array $body): ResponseInterface
    {
        return $this->createJsonResponseMock($body);
    }
}
