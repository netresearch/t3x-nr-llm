<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Error;
use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

#[CoversClass(OllamaProvider::class)]
final class OllamaModelFallbackTest extends AbstractUnitTestCase
{
    #[Test]
    #[DataProvider('failures')]
    public function modelPickerDefaultsSurviveFailedWarningDelivery(
        Throwable $transportFailure,
        Throwable $loggerFailure,
    ): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->willThrowException($loggerFailure);
        $http = $this->createHttpClientWithExpectations();
        $http
            ->expects(self::once())
            ->method('sendRequest')
            ->willThrowException($transportFailure);
        $provider = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $logger,
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure(
            [
                'apiKeyIdentifier' => '',
                'baseUrl' => 'http://localhost:11434',
                'defaultModel' => 'llama3.2',
                'maxRetries' => 0,
            ],
        );
        $provider->setHttpClient($http);

        $models = null;
        $caught = null;

        try {
            $models = $provider->getAvailableModels();
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNull(
            $caught,
            'Failed diagnostics must not replace the model-picker fallback.',
        );
        self::assertSame(
            [
                'llama3.2' => 'Llama 3.2',
                'llama3.2:70b' => 'Llama 3.2 70B',
                'mistral' => 'Mistral',
                'codellama' => 'Code Llama',
                'phi3' => 'Phi-3',
            ],
            $models,
        );
    }

    /**
     * @return iterable<string, array{Throwable, Throwable}>
     */
    public static function failures(): iterable
    {
        yield 'connection exception and logger exception' => [
            new RuntimeException('Endpoint unavailable'),
            new RuntimeException('Warning unavailable'),
        ];
        yield 'connection exception and logger error' => [
            new RuntimeException('Endpoint unavailable'),
            new Error('Warning unavailable'),
        ];
        yield 'transport error and logger exception' => [
            new Error('Endpoint unavailable'),
            new RuntimeException('Warning unavailable'),
        ];
        yield 'transport error and logger error' => [new Error('Endpoint unavailable'), new Error('Warning unavailable')];
    }
}
