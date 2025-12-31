<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for Model domain entity.
 */
#[CoversClass(Model::class)]
final class ModelTest extends TestCase
{
    private function createProviderWithUid(int $uid): Provider
    {
        $provider = new Provider();
        $reflection = new ReflectionClass($provider);
        $uidProperty = $reflection->getProperty('uid');
        $uidProperty->setValue($provider, $uid);
        return $provider;
    }

    // ========================================
    // Provider relation tests
    // ========================================

    #[Test]
    public function getProviderReturnsNullWhenNotSet(): void
    {
        $model = new Model();

        self::assertNull($model->getProvider());
    }

    #[Test]
    public function setProviderSetsProvider(): void
    {
        $model = new Model();
        $provider = $this->createProviderWithUid(456);

        $model->setProvider($provider);

        $actualProvider = $model->getProvider();
        self::assertSame($provider, $actualProvider);
        self::assertNotNull($actualProvider);
        self::assertSame(456, $actualProvider->getUid());
    }

    #[Test]
    public function setProviderWithNullClearsProvider(): void
    {
        $model = new Model();
        $provider = $this->createProviderWithUid(789);
        $model->setProvider($provider);
        $model->setProvider(null);

        self::assertNull($model->getProvider());
    }

    // ========================================
    // Capabilities tests
    // ========================================

    #[Test]
    public function hasCapabilityReturnsTrueForExistingCapability(): void
    {
        $model = new Model();
        $model->setCapabilities('chat,vision,tools');

        self::assertTrue($model->hasCapability(Model::CAPABILITY_CHAT));
        self::assertTrue($model->hasCapability(Model::CAPABILITY_VISION));
        self::assertTrue($model->hasCapability(Model::CAPABILITY_TOOLS));
    }

    #[Test]
    public function hasCapabilityReturnsFalseForMissingCapability(): void
    {
        $model = new Model();
        $model->setCapabilities('chat,vision');

        self::assertFalse($model->hasCapability(Model::CAPABILITY_TOOLS));
        self::assertFalse($model->hasCapability(Model::CAPABILITY_EMBEDDINGS));
    }

    #[Test]
    public function getCapabilitiesArrayReturnsArrayOfCapabilities(): void
    {
        $model = new Model();
        $model->setCapabilities('chat,vision,tools');

        $capabilities = $model->getCapabilitiesArray();

        self::assertIsArray($capabilities);
        self::assertCount(3, $capabilities);
        self::assertContains('chat', $capabilities);
        self::assertContains('vision', $capabilities);
        self::assertContains('tools', $capabilities);
    }

    #[Test]
    public function getCapabilitiesArrayReturnsEmptyArrayWhenNoCapabilities(): void
    {
        $model = new Model();

        self::assertSame([], $model->getCapabilitiesArray());
    }

    // ========================================
    // Display name tests
    // ========================================

    #[Test]
    public function getDisplayNameIncludesProviderWhenAvailable(): void
    {
        $model = new Model();
        $model->setName('GPT-4o');

        $provider = $this->createProviderWithUid(1);
        $provider->setName('OpenAI');
        $model->setProvider($provider);

        self::assertSame('GPT-4o (OpenAI)', $model->getDisplayName());
    }

    #[Test]
    public function getDisplayNameReturnsJustNameWithoutProvider(): void
    {
        $model = new Model();
        $model->setName('GPT-4o');

        self::assertSame('GPT-4o', $model->getDisplayName());
    }

    // ========================================
    // Capability constants tests
    // ========================================

    #[Test]
    public function getAllCapabilitiesReturnsExpectedCapabilities(): void
    {
        $capabilities = Model::getAllCapabilities();

        self::assertIsArray($capabilities);
        self::assertArrayHasKey(Model::CAPABILITY_CHAT, $capabilities);
        self::assertArrayHasKey(Model::CAPABILITY_COMPLETION, $capabilities);
        self::assertArrayHasKey(Model::CAPABILITY_EMBEDDINGS, $capabilities);
        self::assertArrayHasKey(Model::CAPABILITY_VISION, $capabilities);
        self::assertArrayHasKey(Model::CAPABILITY_STREAMING, $capabilities);
        self::assertArrayHasKey(Model::CAPABILITY_TOOLS, $capabilities);
        self::assertArrayHasKey(Model::CAPABILITY_JSON_MODE, $capabilities);
        self::assertArrayHasKey(Model::CAPABILITY_AUDIO, $capabilities);
    }
}
