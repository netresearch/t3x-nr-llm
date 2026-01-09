<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\UnsupportedLanguageException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(UnsupportedLanguageException::class)]
class UnsupportedLanguageExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function forLanguageCreatesTargetLanguageException(): void
    {
        $exception = UnsupportedLanguageException::forLanguage('xyz', 'translation');

        self::assertStringContainsString('Language "xyz" is not supported as target language', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertEquals('xyz', $exception->context['language']);
        self::assertEquals('target', $exception->context['direction']);
        self::assertArrayNotHasKey('supported', $exception->context);
    }

    #[Test]
    public function forLanguageCreatesSourceLanguageException(): void
    {
        $exception = UnsupportedLanguageException::forLanguage('abc', 'translation', 'source');

        self::assertStringContainsString('Language "abc" is not supported as source language', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertEquals('abc', $exception->context['language']);
        self::assertEquals('source', $exception->context['direction']);
    }

    #[Test]
    public function forLanguageIncludesSupportedLanguages(): void
    {
        $supported = ['en', 'de', 'fr', 'es'];
        $exception = UnsupportedLanguageException::forLanguage('xyz', 'translation', 'target', $supported);

        self::assertStringContainsString('Language "xyz" is not supported as target language', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertEquals('xyz', $exception->context['language']);
        self::assertEquals('target', $exception->context['direction']);
        self::assertEquals($supported, $exception->context['supported']);
    }
}
