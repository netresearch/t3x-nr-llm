<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Generator;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;
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
            new LlmConfiguration(),
            function (LlmConfiguration $c) use (&$calls): string {
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
        $next  = function (LlmConfiguration $c) use (&$calls, $response): stdClass {
            $calls++;

            return $response;
        };

        $first  = $middleware->handle($context, new LlmConfiguration(), $next);
        $second = $middleware->handle($context, new LlmConfiguration(), $next);

        self::assertSame($response, $first);
        self::assertSame($response, $second, 'The repeat must return the stored result');
        self::assertSame(1, $calls, 'The provider must be called only once');
    }

    #[Test]
    public function differentKeysDoNotShareResults(): void
    {
        $middleware = $this->middleware();

        $calls = 0;
        $next  = function (LlmConfiguration $c) use (&$calls): string {
            $calls++;

            return 'result-' . $calls;
        };

        $middleware->handle($this->contextWithKey('key-a'), new LlmConfiguration(), $next);
        $middleware->handle($this->contextWithKey('key-b'), new LlmConfiguration(), $next);

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
        $next  = function (LlmConfiguration $c) use (&$calls, $makeGenerator): Generator {
            $calls++;

            return $makeGenerator();
        };

        $middleware->handle($context, new LlmConfiguration(), $next);
        $middleware->handle($context, new LlmConfiguration(), $next);

        // A generator cannot be stored, so the second call is a miss and re-runs.
        self::assertSame(2, $calls);
    }

    #[Test]
    public function doesNotStoreFailedCalls(): void
    {
        $middleware = $this->middleware();
        $context    = $this->contextWithKey('will-fail');

        $calls = 0;
        $next  = function (LlmConfiguration $c) use (&$calls): never {
            $calls++;

            throw new RuntimeException('boom', 1);
        };

        for ($i = 0; $i < 2; $i++) {
            try {
                $middleware->handle($context, new LlmConfiguration(), $next);
                self::fail('Expected the failure to propagate');
            } catch (RuntimeException) {
                // expected
            }
        }

        // A failure is never cached: a retry with the same key genuinely re-runs.
        self::assertSame(2, $calls);
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
            [IdempotencyMiddleware::METADATA_IDEMPOTENCY_KEY => $key],
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
}
