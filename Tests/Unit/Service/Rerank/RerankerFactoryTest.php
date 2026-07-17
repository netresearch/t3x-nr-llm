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
use ReflectionProperty;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

#[CoversClass(RerankerFactory::class)]
final class RerankerFactoryTest extends TestCase
{
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
        self::assertInstanceOf(HttpReranker::class, $this->buildFactory('http://reranker:8081', '30')->create());
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
        self::assertSame(12.5, $this->createdTimeout($this->buildFactory('http://reranker:8081', '12.5')));
    }

    #[Test]
    public function nonNumericTimeoutFallsBackToDefault(): void
    {
        self::assertSame(30.0, $this->createdTimeout($this->buildFactory('http://reranker:8081', 'soon')));
    }

    #[Test]
    public function zeroTimeoutFallsBackToDefault(): void
    {
        self::assertSame(30.0, $this->createdTimeout($this->buildFactory('http://reranker:8081', '0')));
    }

    private function createdTimeout(RerankerFactory $factory): float
    {
        $reranker = $factory->create();
        self::assertInstanceOf(HttpReranker::class, $reranker);

        $timeout = (new ReflectionProperty(HttpReranker::class, 'timeout'))->getValue($reranker);
        self::assertIsFloat($timeout);

        return $timeout;
    }

    private function buildFactory(string $endpoint, string $timeout): RerankerFactory
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnMap([
            ['nr_llm', 'rerankerEndpoint', $endpoint],
            ['nr_llm', 'rerankerTimeout', $timeout],
        ]);

        return new RerankerFactory($this->createMock(RequestFactory::class), $extensionConfiguration);
    }
}
