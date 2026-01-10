<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard\DTO;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DetectedProvider::class)]
class DetectedProviderTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $metadata = ['version' => '1.0', 'region' => 'us-east'];
        $provider = new DetectedProvider(
            adapterType: 'openai',
            suggestedName: 'OpenAI Production',
            endpoint: 'https://api.openai.com/v1',
            confidence: 0.95,
            metadata: $metadata,
        );

        self::assertEquals('openai', $provider->adapterType);
        self::assertEquals('OpenAI Production', $provider->suggestedName);
        self::assertEquals('https://api.openai.com/v1', $provider->endpoint);
        self::assertEquals(0.95, $provider->confidence);
        self::assertEquals($metadata, $provider->metadata);
    }

    #[Test]
    public function constructorUsesDefaultValues(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            suggestedName: 'Claude',
            endpoint: 'https://api.anthropic.com/v1',
        );

        self::assertEquals(1.0, $provider->confidence);
        self::assertEquals([], $provider->metadata);
    }

    #[Test]
    public function toArrayReturnsAllProperties(): void
    {
        $metadata = ['custom' => 'value'];
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            suggestedName: 'Google Gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            confidence: 0.85,
            metadata: $metadata,
        );

        $array = $provider->toArray();

        self::assertEquals([
            'adapterType' => 'gemini',
            'suggestedName' => 'Google Gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
            'confidence' => 0.85,
            'metadata' => ['custom' => 'value'],
        ], $array);
    }

    #[Test]
    public function toArrayIncludesDefaultValues(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            suggestedName: 'Local Ollama',
            endpoint: 'http://localhost:11434',
        );

        $array = $provider->toArray();

        self::assertArrayHasKey('confidence', $array);
        self::assertArrayHasKey('metadata', $array);
        self::assertEquals(1.0, $array['confidence']);
        self::assertEquals([], $array['metadata']);
    }
}
