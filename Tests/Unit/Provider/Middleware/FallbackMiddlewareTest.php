<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Covers the retryable-exception matrix, chain-walk semantics, and all
 * exhaustion / skip / inactive / self-reference edge cases for the
 * fallback behaviour provided by the middleware.
 */
#[CoversClass(FallbackMiddleware::class)]
#[CoversClass(MiddlewarePipeline::class)]
final class FallbackMiddlewareTest extends AbstractUnitTestCase
{
    private LlmConfigurationRepository&Stub $repositoryStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryStub = self::createStub(LlmConfigurationRepository::class);
    }

    #[Test]
    public function returnsResultFromPrimaryOnSuccess(): void
    {
        $primary    = $this->makeConfig('primary', new FallbackChain(['fallback']));
        $pipeline = $this->makePipeline();
        $calls      = [];

        $result = $pipeline->run(
            ProviderCallContext::for(ProviderOperation::Chat),
            $primary,
            function (LlmConfiguration $config) use (&$calls): string {
                $calls[] = $config->getIdentifier();

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertSame(['primary'], $calls);
    }

    #[Test]
    public function skipsFallbackWhenChainIsEmpty(): void
    {
        $primary    = $this->makeConfig('primary', new FallbackChain());
        $pipeline = $this->makePipeline();
        $err        = new ProviderConnectionException('down', 0);

        $caught = $this->captureException(
            ProviderConnectionException::class,
            fn() => $pipeline->run(
                ProviderCallContext::for(ProviderOperation::Chat),
                $primary,
                static function (LlmConfiguration $c) use ($err): never {
                    throw $err;
                },
            ),
        );

        self::assertSame($err, $caught);
    }

    #[Test]
    public function fallsBackToNextConfigurationOnConnectionError(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain(['alt']));
        $alt     = $this->makeConfig('alt');

        $this->repositoryStub->method('findOneByIdentifier')->willReturn($alt);

        $pipeline = $this->makePipeline();
        $calls      = [];

        $result = $pipeline->run(
            ProviderCallContext::for(ProviderOperation::Chat),
            $primary,
            function (LlmConfiguration $config) use (&$calls): string {
                $calls[] = $config->getIdentifier();
                if ($config->getIdentifier() === 'primary') {
                    throw new ProviderConnectionException('down', 0);
                }

                return 'ok-from-alt';
            },
        );

        self::assertSame('ok-from-alt', $result);
        self::assertSame(['primary', 'alt'], $calls);
    }

    #[Test]
    public function retriesOnRateLimitResponse(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain(['alt']));
        $alt     = $this->makeConfig('alt');

        $this->repositoryStub->method('findOneByIdentifier')->willReturn($alt);

        $pipeline = $this->makePipeline();

        $result = $pipeline->run(
            ProviderCallContext::for(ProviderOperation::Chat),
            $primary,
            static function (LlmConfiguration $config): string {
                if ($config->getIdentifier() === 'primary') {
                    throw new ProviderResponseException('rate limited', 429);
                }

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
    }

    #[Test]
    public function doesNotRetryOn4xxResponseOtherThan429(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain(['alt']));
        $alt     = $this->makeConfig('alt');
        $this->repositoryStub->method('findOneByIdentifier')->willReturn($alt);

        $pipeline = $this->makePipeline();
        $calls      = [];
        $err        = new ProviderResponseException('unauthorized', 401);

        $caught = $this->captureException(
            ProviderResponseException::class,
            function () use ($pipeline, $primary, $err, &$calls): never {
                $pipeline->run(
                    ProviderCallContext::for(ProviderOperation::Chat),
                    $primary,
                    static function (LlmConfiguration $config) use (&$calls, $err): never {
                        $calls[] = $config->getIdentifier();

                        throw $err;
                    },
                );
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['primary'], $calls);
    }

    #[Test]
    public function doesNotRetryOnUnsupportedFeature(): void
    {
        $primary    = $this->makeConfig('primary', new FallbackChain(['alt']));
        $pipeline = $this->makePipeline();
        $calls      = [];
        $err        = new UnsupportedFeatureException('nope', 1);

        $caught = $this->captureException(
            UnsupportedFeatureException::class,
            function () use ($pipeline, $primary, $err, &$calls): never {
                $pipeline->run(
                    ProviderCallContext::for(ProviderOperation::Chat),
                    $primary,
                    static function (LlmConfiguration $config) use (&$calls, $err): never {
                        $calls[] = $config->getIdentifier();

                        throw $err;
                    },
                );
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['primary'], $calls);
    }

    #[Test]
    public function doesNotRetryOnConfigurationException(): void
    {
        $primary    = $this->makeConfig('primary', new FallbackChain(['alt']));
        $pipeline = $this->makePipeline();
        $calls      = [];
        $err        = new ProviderConfigurationException('missing key', 1);

        $caught = $this->captureException(
            ProviderConfigurationException::class,
            function () use ($pipeline, $primary, $err, &$calls): never {
                $pipeline->run(
                    ProviderCallContext::for(ProviderOperation::Chat),
                    $primary,
                    static function (LlmConfiguration $config) use (&$calls, $err): never {
                        $calls[] = $config->getIdentifier();

                        throw $err;
                    },
                );
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['primary'], $calls);
    }

    #[Test]
    public function walksEntireChainUntilOneSucceeds(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['a', 'b', 'c']));
        $a       = $this->makeConfig('a');
        $b       = $this->makeConfig('b');
        $c       = $this->makeConfig('c');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['a', $a],
                ['b', $b],
                ['c', $c],
            ]);

        $pipeline = $this->makePipeline();
        $calls      = [];

        $result = $pipeline->run(
            ProviderCallContext::for(ProviderOperation::Chat),
            $primary,
            function (LlmConfiguration $config) use (&$calls): string {
                $calls[] = $config->getIdentifier();
                if (\in_array($config->getIdentifier(), ['p', 'a', 'b'], true)) {
                    throw new ProviderConnectionException('down', 0);
                }

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertSame(['p', 'a', 'b', 'c'], $calls);
    }

    #[Test]
    public function throwsExhaustedWhenEveryAttemptFails(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['a', 'b']));
        $a       = $this->makeConfig('a');
        $b       = $this->makeConfig('b');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['a', $a],
                ['b', $b],
            ]);

        $pipeline = $this->makePipeline();

        $exhausted = $this->captureException(
            FallbackChainExhaustedException::class,
            fn() => $pipeline->run(
                ProviderCallContext::for(ProviderOperation::Chat),
                $primary,
                static function (LlmConfiguration $c): never {
                    throw new ProviderConnectionException('down', 0);
                },
            ),
        );

        self::assertSame(['p', 'a', 'b'], $exhausted->getAttemptedConfigurations());
        self::assertCount(3, $exhausted->getAttemptErrors());
        self::assertInstanceOf(ProviderConnectionException::class, $exhausted->getPrevious());
    }

    #[Test]
    public function skipsMissingFallbackConfigurations(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['missing', 'b']));
        $b       = $this->makeConfig('b');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['missing', null],
                ['b', $b],
            ]);

        $pipeline = $this->makePipeline();
        $calls      = [];

        $result = $pipeline->run(
            ProviderCallContext::for(ProviderOperation::Chat),
            $primary,
            function (LlmConfiguration $config) use (&$calls): string {
                $calls[] = $config->getIdentifier();
                if ($config->getIdentifier() === 'p') {
                    throw new ProviderConnectionException('down', 0);
                }

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertSame(['p', 'b'], $calls);
    }

    #[Test]
    public function skipsInactiveFallbackConfigurations(): void
    {
        $primary  = $this->makeConfig('p', new FallbackChain(['inactive', 'b']));
        $inactive = $this->makeConfig('inactive', active: false);
        $b        = $this->makeConfig('b');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['inactive', $inactive],
                ['b', $b],
            ]);

        $pipeline = $this->makePipeline();
        $calls      = [];

        $result = $pipeline->run(
            ProviderCallContext::for(ProviderOperation::Chat),
            $primary,
            function (LlmConfiguration $config) use (&$calls): string {
                $calls[] = $config->getIdentifier();
                if ($config->getIdentifier() === 'p') {
                    throw new ProviderConnectionException('down', 0);
                }

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertSame(['p', 'b'], $calls);
    }

    #[Test]
    public function rethrowsPrimaryErrorWhenChainContainsOnlyThePrimary(): void
    {
        $primary    = $this->makeConfig('p', new FallbackChain(['p']));
        $pipeline = $this->makePipeline();
        $calls      = [];
        $err        = new ProviderConnectionException('down', 0);

        $caught = $this->captureException(
            ProviderConnectionException::class,
            function () use ($pipeline, $primary, $err, &$calls): never {
                $pipeline->run(
                    ProviderCallContext::for(ProviderOperation::Chat),
                    $primary,
                    static function (LlmConfiguration $config) use (&$calls, $err): never {
                        $calls[] = $config->getIdentifier();

                        throw $err;
                    },
                );
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['p'], $calls, 'Primary should be the only attempt');
    }

    #[Test]
    public function ignoresPrimaryIdentifierAppearingInOwnChain(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['p', 'b']));
        $b       = $this->makeConfig('b');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['p', $primary],
                ['b', $b],
            ]);

        $pipeline = $this->makePipeline();
        $calls      = [];

        $result = $pipeline->run(
            ProviderCallContext::for(ProviderOperation::Chat),
            $primary,
            function (LlmConfiguration $config) use (&$calls): string {
                $calls[] = $config->getIdentifier();
                if ($config->getIdentifier() === 'p') {
                    throw new ProviderConnectionException('down', 0);
                }

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertSame(['p', 'b'], $calls);
    }

    #[Test]
    public function nonProviderExceptionIsNotRetryable(): void
    {
        $primary    = $this->makeConfig('p', new FallbackChain(['a']));
        $pipeline = $this->makePipeline();
        $calls      = [];
        $err        = new RuntimeException('unexpected');

        $caught = $this->captureException(
            RuntimeException::class,
            function () use ($pipeline, $primary, $err, &$calls): never {
                $pipeline->run(
                    ProviderCallContext::for(ProviderOperation::Chat),
                    $primary,
                    static function (LlmConfiguration $config) use (&$calls, $err): never {
                        $calls[] = $config->getIdentifier();

                        throw $err;
                    },
                );
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['p'], $calls);
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    private function makeMiddleware(): FallbackMiddleware
    {
        return new FallbackMiddleware($this->repositoryStub, new NullLogger());
    }

    private function makePipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline([$this->makeMiddleware()]);
    }

    private function makeConfig(
        string $identifier,
        ?FallbackChain $chain = null,
        bool $active = true,
    ): LlmConfiguration {
        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);
        $config->setIsActive($active);
        if ($chain !== null) {
            $config->setFallbackChainDTO($chain);
        }

        return $config;
    }

    /**
     * @template T of Throwable
     *
     * @param class-string<T>   $expected
     * @param callable(): mixed $action
     *
     * @return T
     */
    private function captureException(string $expected, callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $caught) {
            self::assertInstanceOf($expected, $caught);

            return $caught;
        }

        self::fail(\sprintf('Expected %s was not thrown', $expected));
    }
}
