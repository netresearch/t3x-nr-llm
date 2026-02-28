<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider;

use Netresearch\NrLlm\Domain\Model\AdapterType;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
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
        $provider->setAdapterTypeEnum(AdapterType::Ollama);
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
        // Use Ollama adapter since it doesn't require vault-stored API key
        $provider->setAdapterTypeEnum(AdapterType::Ollama);
        // Use non-routable IP (RFC 5737) to avoid DNS resolution delays
        $provider->setEndpointUrl('http://192.0.2.1:11434');
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
        // Message should indicate connection failure (not API key issues)
        self::assertStringContainsString('fail', strtolower($result['message']));
    }

    #[Test]
    public function testOllamaWithDefaultEndpointShouldNotHang(): void
    {
        // Test that Ollama with default localhost endpoint fails fast, not hangs
        $provider = new Provider();
        $provider->setIdentifier('ollama-default');
        $provider->setName('Ollama Default');
        $provider->setAdapterTypeEnum(AdapterType::Ollama);
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
        // Tests should be run inside DDEV where 'ollama:11434' resolves
        if (!$this->isOllamaAvailable()) {
            self::markTestSkipped('Ollama service not available - run tests inside DDEV: ddev exec composer test:functional');
        }

        $provider = new Provider();
        $provider->setIdentifier('ollama-test');
        $provider->setName('Local Ollama');
        $provider->setAdapterTypeEnum(AdapterType::Ollama);
        $provider->setEndpointUrl('http://ollama:11434');
        $provider->setTimeout(10);
        $provider->setIsActive(true);

        $result = $this->registry->testProviderConnection($provider);

        // The test verifies we get a proper response structure, not that Ollama has models
        // This test passes if Ollama responds (even with no models) or reports connection issue
        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('message', $result);
        // If successful, should have models array; otherwise verify we got a proper error (not timeout/hang)
        if ($result['success']) {
            self::assertArrayHasKey('models', $result);
        } else {
            // Connection attempt completed with proper error response
            self::assertNotEmpty($result['message'], 'Error message should not be empty');
        }
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
