<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Service\FallbackChainExecutor;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

#[CoversClass(FallbackChainExecutor::class)]
class FallbackChainExecutorTest extends AbstractUnitTestCase
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
        $primary = $this->makeConfig('primary', new FallbackChain(['fallback']));
        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $result = $executor->execute($primary, function (LlmConfiguration $config) use (&$calls) {
            $calls[] = $config->getIdentifier();
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(['primary'], $calls);
    }

    #[Test]
    public function skipsFallbackWhenChainIsEmpty(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain());
        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $err = new ProviderConnectionException('down', 0);

        $caught = $this->captureException(
            ProviderConnectionException::class,
            fn() => $executor->execute($primary, static function () use ($err): never {
                throw $err;
            }),
        );

        self::assertSame($err, $caught);
    }

    #[Test]
    public function fallsBackToNextConfigurationOnConnectionError(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain(['alt']));
        $alt = $this->makeConfig('alt');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturn($alt);

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $result = $executor->execute($primary, function (LlmConfiguration $config) use (&$calls) {
            $calls[] = $config->getIdentifier();
            if ($config->getIdentifier() === 'primary') {
                throw new ProviderConnectionException('down', 0);
            }
            return 'ok-from-alt';
        });

        self::assertSame('ok-from-alt', $result);
        self::assertSame(['primary', 'alt'], $calls);
    }

    #[Test]
    public function retriesOnRateLimitResponse(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain(['alt']));
        $alt = $this->makeConfig('alt');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturn($alt);

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());

        $result = $executor->execute($primary, static function (LlmConfiguration $config) {
            if ($config->getIdentifier() === 'primary') {
                throw new ProviderResponseException('rate limited', 429);
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
    }

    #[Test]
    public function doesNotRetryOn4xxResponseOtherThan429(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain(['alt']));
        $alt = $this->makeConfig('alt');
        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturn($alt);

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $err = new ProviderResponseException('unauthorized', 401);
        $caught = $this->captureException(
            ProviderResponseException::class,
            function () use ($executor, $primary, $err, &$calls): never {
                $executor->execute($primary, static function (LlmConfiguration $config) use (&$calls, $err): never {
                    $calls[] = $config->getIdentifier();
                    throw $err;
                });
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['primary'], $calls);
    }

    #[Test]
    public function doesNotRetryOnUnsupportedFeature(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain(['alt']));
        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $err = new UnsupportedFeatureException('nope', 1);
        $caught = $this->captureException(
            UnsupportedFeatureException::class,
            function () use ($executor, $primary, $err, &$calls): never {
                $executor->execute($primary, static function (LlmConfiguration $config) use (&$calls, $err): never {
                    $calls[] = $config->getIdentifier();
                    throw $err;
                });
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['primary'], $calls);
    }

    #[Test]
    public function doesNotRetryOnConfigurationException(): void
    {
        $primary = $this->makeConfig('primary', new FallbackChain(['alt']));
        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $err = new ProviderConfigurationException('missing key', 1);
        $caught = $this->captureException(
            ProviderConfigurationException::class,
            function () use ($executor, $primary, $err, &$calls): never {
                $executor->execute($primary, static function (LlmConfiguration $config) use (&$calls, $err): never {
                    $calls[] = $config->getIdentifier();
                    throw $err;
                });
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['primary'], $calls);
    }

    #[Test]
    public function walksEntireChainUntilOneSucceeds(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['a', 'b', 'c']));
        $a = $this->makeConfig('a');
        $b = $this->makeConfig('b');
        $c = $this->makeConfig('c');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['a', $a],
                ['b', $b],
                ['c', $c],
            ]);

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $result = $executor->execute($primary, function (LlmConfiguration $config) use (&$calls) {
            $calls[] = $config->getIdentifier();
            if (in_array($config->getIdentifier(), ['p', 'a', 'b'], true)) {
                throw new ProviderConnectionException('down', 0);
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(['p', 'a', 'b', 'c'], $calls);
    }

    #[Test]
    public function throwsExhaustedWhenEveryAttemptFails(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['a', 'b']));
        $a = $this->makeConfig('a');
        $b = $this->makeConfig('b');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['a', $a],
                ['b', $b],
            ]);

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());

        $exhausted = $this->captureException(
            FallbackChainExhaustedException::class,
            fn() => $executor->execute($primary, static function (): never {
                throw new ProviderConnectionException('down', 0);
            }),
        );

        self::assertSame(['p', 'a', 'b'], $exhausted->getAttemptedConfigurations());
        self::assertCount(3, $exhausted->getAttemptErrors());
        self::assertInstanceOf(ProviderConnectionException::class, $exhausted->getPrevious());
    }

    #[Test]
    public function skipsMissingFallbackConfigurations(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['missing', 'b']));
        $b = $this->makeConfig('b');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['missing', null],
                ['b', $b],
            ]);

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $result = $executor->execute($primary, function (LlmConfiguration $config) use (&$calls) {
            $calls[] = $config->getIdentifier();
            if ($config->getIdentifier() === 'p') {
                throw new ProviderConnectionException('down', 0);
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(['p', 'b'], $calls);
    }

    #[Test]
    public function skipsInactiveFallbackConfigurations(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['inactive', 'b']));
        $inactive = $this->makeConfig('inactive', active: false);
        $b = $this->makeConfig('b');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['inactive', $inactive],
                ['b', $b],
            ]);

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $result = $executor->execute($primary, function (LlmConfiguration $config) use (&$calls) {
            $calls[] = $config->getIdentifier();
            if ($config->getIdentifier() === 'p') {
                throw new ProviderConnectionException('down', 0);
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(['p', 'b'], $calls);
    }

    #[Test]
    public function rethrowsPrimaryErrorWhenChainContainsOnlyTheprimary(): void
    {
        // If the only fallback entry is the primary's own identifier, the
        // post-filter chain is empty. Rethrow the primary error verbatim —
        // do NOT wrap one attempt as "every configuration failed".
        $primary = $this->makeConfig('p', new FallbackChain(['p']));

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];
        $err = new ProviderConnectionException('down', 0);

        $caught = $this->captureException(
            ProviderConnectionException::class,
            function () use ($executor, $primary, $err, &$calls): never {
                $executor->execute($primary, static function (LlmConfiguration $config) use (&$calls, $err): never {
                    $calls[] = $config->getIdentifier();
                    throw $err;
                });
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['p'], $calls, 'Primary should be the only attempt');
    }

    #[Test]
    public function ignoresPrimaryIdentifierAppearingInOwnChain(): void
    {
        // Defensive: if somebody configures 'p' as its own fallback, don't retry it.
        $primary = $this->makeConfig('p', new FallbackChain(['p', 'b']));
        $b = $this->makeConfig('b');

        $this->repositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['p', $primary],
                ['b', $b],
            ]);

        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $result = $executor->execute($primary, function (LlmConfiguration $config) use (&$calls) {
            $calls[] = $config->getIdentifier();
            if ($config->getIdentifier() === 'p') {
                throw new ProviderConnectionException('down', 0);
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(['p', 'b'], $calls);
    }

    #[Test]
    public function nonProviderExceptionIsNotRetryable(): void
    {
        $primary = $this->makeConfig('p', new FallbackChain(['a']));
        $executor = new FallbackChainExecutor($this->repositoryStub, new NullLogger());
        $calls = [];

        $err = new RuntimeException('unexpected');
        $caught = $this->captureException(
            RuntimeException::class,
            function () use ($executor, $primary, $err, &$calls): never {
                $executor->execute($primary, static function (LlmConfiguration $config) use (&$calls, $err): never {
                    $calls[] = $config->getIdentifier();
                    throw $err;
                });
            },
        );

        self::assertSame($err, $caught);
        self::assertSame(['p'], $calls);
    }

    private function makeConfig(string $identifier, ?FallbackChain $chain = null, bool $active = true): LlmConfiguration
    {
        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);
        $config->setIsActive($active);
        if ($chain !== null) {
            $config->setFallbackChainDTO($chain);
        }
        return $config;
    }

    /**
     * Run $action and assert it throws an exception of the expected class.
     *
     * Alternative to wrapping tests in try/catch with self::fail() — PHPStan
     * level 10 flags the self::fail() line as unreachable when the closure
     * always throws. Returning the caught exception lets callers make
     * identity assertions.
     *
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
        self::fail(sprintf('Expected %s was not thrown', $expected));
    }
}
