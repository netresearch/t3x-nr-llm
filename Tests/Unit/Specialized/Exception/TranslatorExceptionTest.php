<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\TranslatorException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(TranslatorException::class)]
class TranslatorExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsPropertiesCorrectly(): void
    {
        $exception = new TranslatorException(
            'Translation failed',
            'translation',
            ['source' => 'en', 'target' => 'de'],
        );

        self::assertEquals('Translation failed', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertEquals(['source' => 'en', 'target' => 'de'], $exception->context);
    }

    #[Test]
    public function exceptionWithoutContextWorks(): void
    {
        $exception = new TranslatorException('API error', 'translation');

        self::assertEquals('API error', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertNull($exception->context);
    }
}
