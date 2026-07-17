<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Rerank;

use Netresearch\NrLlm\Service\Rerank\HttpReranker;
use Netresearch\NrLlm\Service\Rerank\NullReranker;
use Netresearch\NrLlm\Service\Rerank\RerankerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

#[CoversClass(RerankerFactory::class)]
final class RerankerFactoryTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $requestOptions = [];

    #[Test]
    public function emptyEndpointSelectsNullReranker(): void
    {
        self::assertInstanceOf(NullReranker::class, $this->buildFactory('', '30')->create());
    }

    #[Test]
    public function whitespaceOnlyEndpointSelectsNullReranker(): void
    {
        self::assertInstanceOf(NullReranker::class, $this->buildFactory('   ', '30')->create());
    }

    #[Test]
    public function configuredEndpointSelectsHttpReranker(): void
    {
        self::assertInstanceOf(HttpReranker::class, $this->buildFactory('https://reranker:8081', '30')->create());
    }

    #[Test]
    public function unreadableConfigurationFailsOpenToNullReranker(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new ExtensionConfigurationExtensionNotConfiguredException('not configured', 1784750101),
        );
        $factory = new RerankerFactory($this->createMock(RequestFactory::class), $extensionConfiguration);

        self::assertInstanceOf(NullReranker::class, $factory->create());
    }

    #[Test]
    public function configuredTimeoutIsPassedToHttpReranker(): void
    {
        self::assertSame(12.5, $this->createdTimeout($this->buildFactory('https://reranker:8081', '12.5')));
    }

    #[Test]
    public function integerTypedTimeoutIsAccepted(): void
    {
        // int+-typed template fields may come back as int when set
        // programmatically instead of through the install tool.
        self::assertSame(45.0, $this->createdTimeout($this->buildFactory('https://reranker:8081', 45)));
    }

    #[Test]
    public function nonNumericTimeoutFallsBackToDefault(): void
    {
        self::assertSame(30.0, $this->createdTimeout($this->buildFactory('https://reranker:8081', 'soon')));
    }

    #[Test]
    public function zeroTimeoutFallsBackToDefault(): void
    {
        self::assertSame(30.0, $this->createdTimeout($this->buildFactory('https://reranker:8081', '0')));
    }

    /**
     * The configured timeout is observable through the public API as the
     * per-request ``timeout`` option the created reranker sends.
     */
    private function createdTimeout(RerankerFactory $factory): float
    {
        $reranker = $factory->create();
        self::assertInstanceOf(HttpReranker::class, $reranker);

        $reranker->rerank('query', [['id' => 'a', 'text' => 'passage']]);

        self::assertCount(1, $this->requestOptions);
        $timeout = $this->requestOptions[0]['timeout'] ?? null;
        self::assertIsFloat($timeout);

        return $timeout;
    }

    private function buildFactory(string $endpoint, string|int $timeout): RerankerFactory
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnMap([
            ['nr_llm', 'rerankerEndpoint', $endpoint],
            ['nr_llm', 'rerankerTimeout', $timeout],
        ]);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $url, string $method, array $options): ResponseInterface {
                /** @var array<string, mixed> $captured */
                $captured = [...$options, '_url' => $url, '_method' => $method];
                $this->requestOptions[] = $captured;

                return $this->jsonResponse('{"scores": []}');
            },
        );

        return new RerankerFactory($requestFactory, $extensionConfiguration);
    }

    private function jsonResponse(string $body): ResponseInterface
    {
        $stream = self::createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
