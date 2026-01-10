<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\ServiceQuotaExceededException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ServiceQuotaExceededException::class)]
class ServiceQuotaExceededExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function rateLimitExceededCreatesBasicException(): void
    {
        $exception = ServiceQuotaExceededException::rateLimitExceeded('chat');

        self::assertEquals('Rate limit exceeded', $exception->getMessage());
        self::assertEquals('chat', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('rate_limit', $exception->context['type']);
        self::assertNull($exception->context['retry_after']);
    }

    #[Test]
    public function rateLimitExceededIncludesRetryAfter(): void
    {
        $exception = ServiceQuotaExceededException::rateLimitExceeded('translation', 60);

        self::assertStringContainsString('Retry after 60 seconds', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('rate_limit', $exception->context['type']);
        self::assertEquals(60, $exception->context['retry_after']);
    }

    #[Test]
    public function quotaExceededCreatesBasicException(): void
    {
        $exception = ServiceQuotaExceededException::quotaExceeded('translation', 'characters');

        self::assertEquals('Characters quota exceeded', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('quota', $exception->context['type']);
        self::assertEquals('characters', $exception->context['quota_type']);
        self::assertNull($exception->context['limit']);
        self::assertNull($exception->context['used']);
    }

    #[Test]
    public function quotaExceededIncludesLimitAndUsage(): void
    {
        $exception = ServiceQuotaExceededException::quotaExceeded('chat', 'tokens', 100000, 150000);

        self::assertStringContainsString('Tokens quota exceeded', $exception->getMessage());
        self::assertStringContainsString('used: 150000', $exception->getMessage());
        self::assertStringContainsString('limit: 100000', $exception->getMessage());
        self::assertEquals('chat', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('quota', $exception->context['type']);
        self::assertEquals('tokens', $exception->context['quota_type']);
        self::assertEquals(100000, $exception->context['limit']);
        self::assertEquals(150000, $exception->context['used']);
    }

    #[Test]
    public function quotaExceededWorksWithFloatValues(): void
    {
        $exception = ServiceQuotaExceededException::quotaExceeded('image', 'credits', 100.5, 120.75);

        self::assertStringContainsString('used: 120.75', $exception->getMessage());
        self::assertStringContainsString('limit: 100.5', $exception->getMessage());
        self::assertNotNull($exception->context);
        self::assertEquals(100.5, $exception->context['limit']);
        self::assertEquals(120.75, $exception->context['used']);
    }
}
