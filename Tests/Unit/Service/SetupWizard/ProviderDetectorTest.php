<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\ProviderDetector;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ProviderDetector::class)]
class ProviderDetectorTest extends AbstractUnitTestCase
{
    private ProviderDetector $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ProviderDetector();
    }

    // ==================== OpenAI detection ====================

    #[Test]
    public function detectRecognizesOpenAI(): void
    {
        $result = $this->subject->detect('https://api.openai.com/v1');

        self::assertEquals('openai', $result->adapterType);
        self::assertEquals('OpenAI', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    #[Test]
    public function detectRecognizesOpenAIWithoutScheme(): void
    {
        $result = $this->subject->detect('api.openai.com');

        self::assertEquals('openai', $result->adapterType);
        self::assertEquals('https://api.openai.com', $result->endpoint);
    }

    #[Test]
    public function detectRecognizesOpenAIBaseDomain(): void
    {
        $result = $this->subject->detect('https://openai.com/api');

        self::assertEquals('openai', $result->adapterType);
        self::assertEquals(0.9, $result->confidence);
    }

    // ==================== Anthropic detection ====================

    #[Test]
    public function detectRecognizesAnthropic(): void
    {
        $result = $this->subject->detect('https://api.anthropic.com/v1');

        self::assertEquals('anthropic', $result->adapterType);
        self::assertEquals('Anthropic', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    #[Test]
    public function detectRecognizesAnthropicBaseDomain(): void
    {
        $result = $this->subject->detect('https://anthropic.com');

        self::assertEquals('anthropic', $result->adapterType);
        self::assertEquals(0.9, $result->confidence);
    }

    // ==================== Google Gemini detection ====================

    #[Test]
    public function detectRecognizesGemini(): void
    {
        $result = $this->subject->detect('https://generativelanguage.googleapis.com/v1');

        self::assertEquals('gemini', $result->adapterType);
        self::assertEquals('Google Gemini', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    #[Test]
    public function detectRecognizesVertexAI(): void
    {
        $result = $this->subject->detect('https://aiplatform.googleapis.com');

        self::assertEquals('gemini', $result->adapterType);
        self::assertEquals('Google Vertex AI', $result->suggestedName);
        self::assertEquals(0.95, $result->confidence);
    }

    // ==================== OpenRouter detection ====================

    #[Test]
    public function detectRecognizesOpenRouter(): void
    {
        $result = $this->subject->detect('https://openrouter.ai/api/v1');

        self::assertEquals('openrouter', $result->adapterType);
        self::assertEquals('OpenRouter', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    // ==================== Mistral detection ====================

    #[Test]
    public function detectRecognizesMistral(): void
    {
        $result = $this->subject->detect('https://api.mistral.ai');

        self::assertEquals('mistral', $result->adapterType);
        self::assertEquals('Mistral AI', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    #[Test]
    public function detectRecognizesMistralBaseDomain(): void
    {
        $result = $this->subject->detect('https://mistral.ai');

        self::assertEquals('mistral', $result->adapterType);
        self::assertEquals(0.9, $result->confidence);
    }

    // ==================== Groq detection ====================

    #[Test]
    public function detectRecognizesGroq(): void
    {
        $result = $this->subject->detect('https://api.groq.com/openai/v1');

        self::assertEquals('groq', $result->adapterType);
        self::assertEquals('Groq', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    #[Test]
    public function detectRecognizesGroqBaseDomain(): void
    {
        $result = $this->subject->detect('https://groq.com');

        self::assertEquals('groq', $result->adapterType);
        self::assertEquals(0.9, $result->confidence);
    }

    // ==================== Ollama detection ====================

    #[Test]
    public function detectRecognizesOllamaByHostname(): void
    {
        $result = $this->subject->detect('http://ollama:11434');

        self::assertEquals('ollama', $result->adapterType);
        self::assertEquals('Local Ollama', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
        self::assertTrue($result->metadata['local']);
    }

    #[Test]
    public function detectRecognizesOllamaByPort(): void
    {
        $result = $this->subject->detect('http://localhost:11434');

        self::assertEquals('ollama', $result->adapterType);
        self::assertTrue($result->metadata['local']);
    }

    #[Test]
    public function detectRecognizesOllamaOnAnyHostWithDefaultPort(): void
    {
        $result = $this->subject->detect('http://192.168.1.100:11434');

        self::assertEquals('ollama', $result->adapterType);
    }

    // ==================== Azure OpenAI detection ====================

    #[Test]
    public function detectRecognizesAzureOpenAI(): void
    {
        $result = $this->subject->detect('https://myresource.openai.azure.com');

        self::assertEquals('azure_openai', $result->adapterType);
        self::assertStringContainsString('Azure OpenAI', $result->suggestedName);
        self::assertStringContainsString('myresource', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
        self::assertEquals('myresource', $result->metadata['resourceName']);
    }

    #[Test]
    public function detectRecognizesAzureOpenAIWithSubdomain(): void
    {
        $result = $this->subject->detect('https://prod-instance.openai.azure.com/openai');

        self::assertEquals('azure_openai', $result->adapterType);
        self::assertEquals('prod-instance', $result->metadata['resourceName']);
    }

    // ==================== Together AI detection ====================

    #[Test]
    public function detectRecognizesTogetherAI(): void
    {
        $result = $this->subject->detect('https://api.together.xyz');

        self::assertEquals('together', $result->adapterType);
        self::assertEquals('Together AI', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    // ==================== Fireworks detection ====================

    #[Test]
    public function detectRecognizesFireworks(): void
    {
        $result = $this->subject->detect('https://api.fireworks.ai/v1');

        self::assertEquals('fireworks', $result->adapterType);
        self::assertEquals('Fireworks AI', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    // ==================== Perplexity detection ====================

    #[Test]
    public function detectRecognizesPerplexity(): void
    {
        $result = $this->subject->detect('https://api.perplexity.ai');

        self::assertEquals('perplexity', $result->adapterType);
        self::assertEquals('Perplexity', $result->suggestedName);
        self::assertEquals(1.0, $result->confidence);
    }

    // ==================== OpenAI-compatible detection ====================

    #[Test]
    public function detectRecognizesOpenAICompatibleByPath(): void
    {
        $result = $this->subject->detect('https://custom-llm.example.com/v1/chat/completions');

        self::assertEquals('openai', $result->adapterType);
        self::assertStringContainsString('OpenAI-Compatible', $result->suggestedName);
        self::assertEquals(0.6, $result->confidence);
        self::assertTrue($result->metadata['openaiCompatible']);
    }

    #[Test]
    public function detectRecognizesOpenAICompatibleByCompletionsPath(): void
    {
        $result = $this->subject->detect('https://llm.example.com/v1/completions');

        self::assertEquals('openai', $result->adapterType);
        self::assertEquals(0.6, $result->confidence);
    }

    #[Test]
    public function detectRecognizesOpenAICompatibleByModelsPath(): void
    {
        $result = $this->subject->detect('https://llm.example.com/v1/models');

        self::assertEquals('openai', $result->adapterType);
    }

    #[Test]
    public function detectRecognizesOpenAICompatibleByEmbeddingsPath(): void
    {
        $result = $this->subject->detect('https://llm.example.com/v1/embeddings');

        self::assertEquals('openai', $result->adapterType);
    }

    #[Test]
    public function detectRecognizesOpenAICompatibleByKeyword(): void
    {
        $result = $this->subject->detect('https://my-openai-proxy.example.com');

        self::assertEquals('openai', $result->adapterType);
        self::assertEquals(0.6, $result->confidence);
    }

    // ==================== Unknown endpoint detection ====================

    #[Test]
    public function detectReturnsUnknownForUnrecognizedEndpoint(): void
    {
        $result = $this->subject->detect('https://custom-llm.example.com');

        self::assertEquals('openai', $result->adapterType);
        self::assertStringContainsString('Custom Provider', $result->suggestedName);
        self::assertEquals(0.3, $result->confidence);
        self::assertTrue($result->metadata['unknown']);
        self::assertTrue($result->metadata['openaiCompatible']);
    }

    // ==================== Endpoint normalization ====================

    #[Test]
    public function detectNormalizesEndpointWithTrailingSlash(): void
    {
        $result = $this->subject->detect('https://api.openai.com/v1/');

        self::assertEquals('https://api.openai.com/v1', $result->endpoint);
    }

    #[Test]
    public function detectAddsHttpsToMissingScheme(): void
    {
        $result = $this->subject->detect('api.anthropic.com');

        self::assertEquals('https://api.anthropic.com', $result->endpoint);
    }

    #[Test]
    public function detectAddsHttpToLocalhost(): void
    {
        $result = $this->subject->detect('localhost:8080');

        self::assertEquals('http://localhost:8080', $result->endpoint);
    }

    #[Test]
    public function detectAddsHttpTo127001(): void
    {
        $result = $this->subject->detect('127.0.0.1:8080');

        self::assertEquals('http://127.0.0.1:8080', $result->endpoint);
    }

    #[Test]
    public function detectTrimsWhitespace(): void
    {
        $result = $this->subject->detect('  https://api.openai.com  ');

        self::assertEquals('https://api.openai.com', $result->endpoint);
    }

    // ==================== getSupportedAdapterTypes ====================

    #[Test]
    public function getSupportedAdapterTypesReturnsAllTypes(): void
    {
        $types = $this->subject->getSupportedAdapterTypes();

        self::assertArrayHasKey('openai', $types);
        self::assertArrayHasKey('anthropic', $types);
        self::assertArrayHasKey('gemini', $types);
        self::assertArrayHasKey('openrouter', $types);
        self::assertArrayHasKey('mistral', $types);
        self::assertArrayHasKey('groq', $types);
        self::assertArrayHasKey('ollama', $types);
        self::assertArrayHasKey('azure_openai', $types);
        self::assertArrayHasKey('together', $types);
        self::assertArrayHasKey('fireworks', $types);
        self::assertArrayHasKey('perplexity', $types);
        self::assertArrayHasKey('custom', $types);
    }

    #[Test]
    public function getSupportedAdapterTypesReturnsHumanReadableNames(): void
    {
        $types = $this->subject->getSupportedAdapterTypes();

        self::assertEquals('OpenAI', $types['openai']);
        self::assertEquals('Anthropic', $types['anthropic']);
        self::assertEquals('Ollama (Local)', $types['ollama']);
    }
}
