<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Generator;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

#[CoversClass(IdempotencyMiddleware::class)]
final class IdempotencyMiddlewareTest extends AbstractUnitTestCase
{
    #[Test]
    public function passesThroughWhenNoKeyIsPresent(): void
    {
        $calls      = 0;
        $middleware = $this->middleware();

        $result = $middleware->handle(
            new ProviderCallContext(ProviderOperation::Chat, 'corr'),
            function () use (&$calls): string {
                $calls++;

                return 'fresh';
            },
        );

        self::assertSame('fresh', $result);
        self::assertSame(1, $calls);
    }

    #[Test]
    public function replaysStoredResultForARepeatedKeyWithoutCallingTheProvider(): void
    {
        $middleware = $this->middleware();
        $context    = $this->contextWithKey('order-42');
        $response   = new stdClass();
        $response->answer = 'generated once';

        $calls = 0;
        $next  = function () use (&$calls, $response): stdClass {
            $calls++;

            return $response;
        };

        $first  = $middleware->handle($context, $next);
        $second = $middleware->handle($context, $next);

        self::assertSame($response, $first);
        self::assertSame($response, $second, 'The repeat must return the stored result');
        self::assertSame(1, $calls, 'The provider must be called only once');
    }

    #[Test]
    public function differentKeysDoNotShareResults(): void
    {
        $middleware = $this->middleware();

        $calls = 0;
        $next  = function () use (&$calls): string {
            $calls++;

            return 'result-' . $calls;
        };

        $middleware->handle($this->contextWithKey('key-a'), $next);
        $middleware->handle($this->contextWithKey('key-b'), $next);

        self::assertSame(2, $calls, 'A different key is a miss and must reach the provider');
    }

    #[Test]
    public function doesNotStoreStreamingGenerators(): void
    {
        $middleware = $this->middleware();
        $context    = $this->contextWithKey('stream-1');

        // The yield lives in a separate factory, so $next itself is an ordinary
        // closure whose $calls++ runs on invocation (a generator function's body
        // would not execute until iterated, defeating the counter).
        $makeGenerator = static function (): Generator {
            yield 'chunk';
        };
        $calls = 0;
        $next  = function () use (&$calls, $makeGenerator): Generator {
            $calls++;

            return $makeGenerator();
        };

        $middleware->handle($context, $next);
        $middleware->handle($context, $next);

        // A generator cannot be stored, so the second call is a miss and re-runs.
        self::assertSame(2, $calls);
    }

    #[Test]
    public function doesNotStoreFailedCalls(): void
    {
        $middleware = $this->middleware();
        $context = $this->contextWithKey('will-fail');
        $expectedFailure = new RuntimeException('boom', 1);
        $calls = 0;
        $next = static function () use (&$calls, $expectedFailure): never {
            $calls++;
            throw $expectedFailure;
        };
        for ($i = 0; $i < 2; $i++) {
            $actualFailure = null;
            try {
                $middleware->handle($context, $next);
            } catch (RuntimeException $exception) {
                $actualFailure = $exception;
            }

            self::assertSame(
                $expectedFailure,
                $actualFailure,
                'Each retry must propagate the original provider failure.',
            );
        }

        self::assertSame(2, $calls, 'Failed calls must never be cached.');
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    private function middleware(): IdempotencyMiddleware
    {
        return new IdempotencyMiddleware($this->cacheManager());
    }

    private function contextWithKey(string $key): ProviderCallContext
    {
        return new ProviderCallContext(
            ProviderOperation::Chat,
            'corr',
            metadata: [IdempotencyMiddleware::METADATA_IDEMPOTENCY_KEY => $key],
        );
    }

    /**
     * A cache manager whose frontend is an in-memory fake, so store/replay is
     * exercised against real read-through behaviour rather than a mock return.
     */
    private function cacheManager(): Typo3CacheManager
    {
        $storage = [];

        $frontend = self::createStub(FrontendInterface::class);
        $frontend->method('set')->willReturnCallback(
            function (string $entryIdentifier, mixed $data) use (&$storage): void {
                $storage[$entryIdentifier] = $data;
            },
        );
        $frontend->method('get')->willReturnCallback(
            // Regular closure (by-reference capture): an arrow fn would snapshot
            // $storage by value at definition time and never see a stored entry.
            function (string $entryIdentifier) use (&$storage): mixed {
                return $storage[$entryIdentifier] ?? false;
            },
        );

        $cacheManager = self::createStub(Typo3CacheManager::class);
        $cacheManager->method('getCache')->willReturn($frontend);

        return $cacheManager;
    }

    #[Test]
    #[DataProvider('distinctKeysWithTheSameReadablePrefix')]
    public function distinctRawKeysKeepSeparateResultsAndReplayTheirOwnAnswer(
        string $firstKey,
        string $secondKey,
    ): void {
        $middleware = $this->middleware();
        $calls = 0;
        $next = static function () use (&$calls): string {
            return 'answer-' . ++$calls;
        };
        self::assertSame(
            'answer-1',
            $middleware->handle($this->contextWithKey($firstKey), $next),
        );
        self::assertSame(
            'answer-2',
            $middleware->handle($this->contextWithKey($secondKey), $next),
        );
        self::assertSame(
            'answer-1',
            $middleware->handle($this->contextWithKey($firstKey), $next),
        );
        self::assertSame(
            'answer-2',
            $middleware->handle($this->contextWithKey($secondKey), $next),
        );
        self::assertSame(2, $calls);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function distinctKeysWithTheSameReadablePrefix(): iterable
    {
        yield 'same sanitized prefix' => ['order:42', 'order?42'];
        yield 'same truncated prefix' => [str_repeat('a', 64) . 'first', str_repeat('a', 64) . 'second'];
    }

    #[Test]
    #[DataProvider('absentKeys')]
    public function unusableKeysBypassTheCache(mixed $key): void
    {
        $frontend = self::createMock(FrontendInterface::class);
        $frontend->expects(self::never())->method('get');
        $frontend->expects(self::never())->method('set');
        $response = new stdClass();
        $context = new ProviderCallContext(
            ProviderOperation::Chat,
            'corr',
            metadata: [IdempotencyMiddleware::METADATA_IDEMPOTENCY_KEY => $key],
        );
        $result = null;
        $failure = null;
        try {
            $result = $this
                ->middlewareWithFrontend($frontend)
                ->handle($context, static fn(): stdClass => $response);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        self::assertNull(
            $failure,
            'An unusable idempotency key must pass through without cache failures.',
        );
        self::assertSame($response, $result);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function absentKeys(): iterable
    {
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'integer' => [42];
        yield 'boolean' => [true];
        yield 'array' => [['key']];
    }

    #[Test]
    public function aCacheReadFailureStillReturnsAndStoresTheFreshAnswer(): void
    {
        $response = new stdClass();
        $frontend = self::createMock(FrontendInterface::class);
        $frontend
            ->expects(self::once())
            ->method('get')
            ->willThrowException(new RuntimeException('cache read failed', 1));
        $frontend
            ->expects(self::once())
            ->method('set')
            ->with(self::isString(), $response, [], 86400);
        $result = null;
        $failure = null;
        $calls = 0;
        try {
            $result = $this
                ->middlewareWithFrontend($frontend)
                ->handle(
                    $this->contextWithKey('read-failure'),
                    static function () use (&$calls, $response): stdClass {
                        $calls++;
                        return $response;
                    },
                );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        self::assertNull(
            $failure,
            'A cache read failure must not replace a successful provider answer.',
        );
        self::assertSame($response, $result);
        self::assertSame(1, $calls);
    }

    #[Test]
    public function cacheResolutionFailureDoesNotBlockOrInventAReplay(): void
    {
        $manager = self::createStub(Typo3CacheManager::class);
        $manager
            ->method('getCache')
            ->willThrowException(new RuntimeException('cache not available', 1));
        $middleware = new IdempotencyMiddleware($manager);
        $results = [];
        $failure = null;
        $calls = 0;
        $next = static function () use (&$calls): string {
            return 'answer-' . ++$calls;
        };
        try {
            $results[] = $middleware->handle(
                $this->contextWithKey('resolution-failure'),
                $next,
            );
            $results[] = $middleware->handle(
                $this->contextWithKey('resolution-failure'),
                $next,
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        self::assertNull($failure);
        self::assertSame(['answer-1', 'answer-2'], $results);
        self::assertSame(2, $calls);
    }

    #[Test]
    public function aCacheStoreFailureReturnsTheFreshAnswerAndAllowsARetry(): void
    {
        $frontend = self::createMock(FrontendInterface::class);
        $frontend->expects(self::exactly(2))->method('get')->willReturn(false);
        $frontend
            ->expects(self::exactly(2))
            ->method('set')
            ->willThrowException(new RuntimeException('cache store failed', 1));
        $middleware = $this->middlewareWithFrontend($frontend);
        $calls = 0;
        $results = [];
        $failure = null;
        $next = static function () use (&$calls): string {
            return 'answer-' . ++$calls;
        };
        try {
            $results[] = $middleware->handle($this->contextWithKey('store-failure'), $next);
            $results[] = $middleware->handle($this->contextWithKey('store-failure'), $next);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        self::assertNull(
            $failure,
            'Best-effort persistence must not replace a successful provider answer.',
        );
        self::assertSame(['answer-1', 'answer-2'], $results);
        self::assertSame(2, $calls);
    }

    private function middlewareWithFrontend(
        FrontendInterface $frontend,
    ): IdempotencyMiddleware {
        $manager = self::createStub(Typo3CacheManager::class);
        $manager->method('getCache')->willReturn($frontend);
        return new IdempotencyMiddleware($manager);
    }
}
