<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ServiceConfigurationException::class)]
class ServiceConfigurationExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function invalidApiKeyCreatesCorrectException(): void
    {
        $exception = ServiceConfigurationException::invalidApiKey('translation', 'deepl');

        self::assertStringContainsString('Deepl API authentication failed', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('invalid_api_key', $exception->context['reason']);
        self::assertEquals('deepl', $exception->context['provider']);
    }

    #[Test]
    public function invalidApiKeyCapitalizesProviderName(): void
    {
        $exception = ServiceConfigurationException::invalidApiKey('speech', 'openai');

        self::assertStringStartsWith('Openai', $exception->getMessage());
    }

    #[Test]
    public function missingOptionCreatesCorrectException(): void
    {
        $exception = ServiceConfigurationException::missingOption('image', 'dalle', 'api_key');

        self::assertStringContainsString('Dalle requires configuration option "api_key"', $exception->getMessage());
        self::assertEquals('image', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('missing_option', $exception->context['reason']);
        self::assertEquals('dalle', $exception->context['provider']);
        self::assertEquals('api_key', $exception->context['option']);
    }

    #[Test]
    public function invalidValueCreatesCorrectException(): void
    {
        $exception = ServiceConfigurationException::invalidValue(
            'translation',
            'deepl',
            'target_language',
            'must be a valid ISO language code',
        );

        self::assertStringContainsString('Deepl configuration error for "target_language"', $exception->getMessage());
        self::assertStringContainsString('must be a valid ISO language code', $exception->getMessage());
        self::assertEquals('translation', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('invalid_value', $exception->context['reason']);
        self::assertEquals('deepl', $exception->context['provider']);
        self::assertEquals('target_language', $exception->context['option']);
    }
}
