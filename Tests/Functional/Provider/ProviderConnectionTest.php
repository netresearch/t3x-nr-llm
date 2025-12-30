<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider;

use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for provider connection testing.
 *
 * These tests verify that provider connections can be tested
 * without hanging indefinitely.
 */
#[CoversClass(ProviderAdapterRegistry::class)]
final class ProviderConnectionTest extends AbstractFunctionalTestCase
{
    private ProviderAdapterRegistry $registry;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $registry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $registry);
        $this->registry = $registry;
    }

    #[Test]
    public function testProviderConnectionReturnsWithinTimeout(): void
    {
        $provider = new Provider();
        $provider->setIdentifier('test-ollama');
        $provider->setName('Test Ollama');
        $provider->setAdapterType(Provider::ADAPTER_OLLAMA);
        $provider->setEndpointUrl('http://ollama:11434');
        $provider->setTimeout(5); // 5 second timeout
        $provider->setMaxRetries(1); // No retries for connection tests
        $provider->setIsActive(true);

        $startTime = microtime(true);
        $result = $this->registry->testProviderConnection($provider);
        $elapsed = microtime(true) - $startTime;

        // Should return within timeout + small buffer, never hang
        // Allow 15s to account for DNS resolution and network delays
        self::assertLessThan(15, $elapsed, 'Connection test should not hang');
        // Result is always an array with 'success' and 'message' keys
        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('message', $result);
    }

    #[Test]
    public function testProviderConnectionToUnreachableHostReturnsError(): void
    {
        $provider = new Provider();
        $provider->setIdentifier('test-unreachable');
        $provider->setName('Unreachable Provider');
        $provider->setAdapterType(Provider::ADAPTER_OPENAI); // Use OpenAI, not Ollama (Ollama has fallback)
        // Use non-routable IP (RFC 5737) to avoid DNS resolution delays
        $provider->setEndpointUrl('http://192.0.2.1:11434');
        $provider->setApiKey('fake-key'); // Required for OpenAI
        $provider->setTimeout(3); // Short timeout
        $provider->setMaxRetries(1); // No retries for connection tests
        $provider->setIsActive(true);

        $startTime = microtime(true);
        $result = $this->registry->testProviderConnection($provider);
        $elapsed = microtime(true) - $startTime;

        // Should fail quickly, not hang
        // Allow 15s to account for network delays and retries
        self::assertLessThan(15, $elapsed, 'Connection test to unreachable host should not hang');
        self::assertFalse($result['success']);
        self::assertStringContainsString('failed', strtolower($result['message']));
    }

    #[Test]
    public function testOllamaWithDefaultEndpointShouldNotHang(): void
    {
        // Test that Ollama with default localhost endpoint fails fast, not hangs
        $provider = new Provider();
        $provider->setIdentifier('ollama-default');
        $provider->setName('Ollama Default');
        $provider->setAdapterType(Provider::ADAPTER_OLLAMA);
        // Intentionally NOT setting endpointUrl to test default behavior
        $provider->setTimeout(3);
        $provider->setMaxRetries(1); // No retries for connection tests
        $provider->setIsActive(true);

        $startTime = microtime(true);
        $result = $this->registry->testProviderConnection($provider);
        $elapsed = microtime(true) - $startTime;

        // Should return within timeout, never hang forever
        self::assertLessThan(10, $elapsed, 'Ollama with default endpoint should not hang');
        // Result is always an array - check that it has expected keys
        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('message', $result);
        // Note: This may succeed or fail depending on environment
    }

    #[Test]
    public function testOllamaProviderConnectionWithValidEndpoint(): void
    {
        // Skip if Ollama is not available in test environment
        if (!$this->isOllamaAvailable()) {
            self::markTestSkipped('Ollama service not available');
        }

        $provider = new Provider();
        $provider->setIdentifier('ollama-test');
        $provider->setName('Local Ollama');
        $provider->setAdapterType(Provider::ADAPTER_OLLAMA);
        $provider->setEndpointUrl('http://ollama:11434');
        $provider->setTimeout(10);
        $provider->setIsActive(true);

        $result = $this->registry->testProviderConnection($provider);

        self::assertTrue($result['success'], 'Ollama connection should succeed: ' . $result['message']);
        self::assertArrayHasKey('models', $result);
    }

    private function isOllamaAvailable(): bool
    {
        $socket = @fsockopen('ollama', 11434, $errno, $errstr, 2);
        if ($socket) {
            fclose($socket);
            return true;
        }
        return false;
    }
}
