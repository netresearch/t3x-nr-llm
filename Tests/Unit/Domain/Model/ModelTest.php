<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\DTO\CapabilitySet;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for Model domain entity.
 *
 * Note: No CoversClass attribute as Domain/Model is excluded from coverage.
 */
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
        self::assertSame(456, $provider->getUid());
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

        self::assertTrue($model->hasCapability(ModelCapability::CHAT->value));
        self::assertTrue($model->hasCapability(ModelCapability::VISION->value));
        self::assertTrue($model->hasCapability(ModelCapability::TOOLS->value));
    }

    #[Test]
    public function hasCapabilityReturnsFalseForMissingCapability(): void
    {
        $model = new Model();
        $model->setCapabilities('chat,vision');

        self::assertFalse($model->hasCapability(ModelCapability::TOOLS->value));
        self::assertFalse($model->hasCapability(ModelCapability::EMBEDDINGS->value));
    }

    #[Test]
    public function getCapabilitiesArrayReturnsArrayOfCapabilities(): void
    {
        $model = new Model();
        $model->setCapabilities('chat,vision,tools');

        $capabilities = $model->getCapabilitiesArray();

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
    // Capability label map (UI helper used by ModelController)
    // ========================================

    #[Test]
    public function getAllCapabilitiesReturnsExpectedCapabilities(): void
    {
        // After REC #10 the legacy `Model::CAPABILITY_*` constants are
        // gone; the source of truth is the `ModelCapability` enum.
        // `getAllCapabilities()` stays as a UI label-map helper used
        // by `ModelController` and is keyed on the enum values.
        $capabilities = Model::getAllCapabilities();

        self::assertArrayHasKey(ModelCapability::CHAT->value, $capabilities);
        self::assertArrayHasKey(ModelCapability::COMPLETION->value, $capabilities);
        self::assertArrayHasKey(ModelCapability::EMBEDDINGS->value, $capabilities);
        self::assertArrayHasKey(ModelCapability::VISION->value, $capabilities);
        self::assertArrayHasKey(ModelCapability::STREAMING->value, $capabilities);
        self::assertArrayHasKey(ModelCapability::TOOLS->value, $capabilities);
        self::assertArrayHasKey(ModelCapability::JSON_MODE->value, $capabilities);
        self::assertArrayHasKey(ModelCapability::AUDIO->value, $capabilities);
    }

    // ========================================
    // CapabilitySet (REC #6 slice 16a)
    // ========================================

    #[Test]
    public function getCapabilitySetReturnsTypedDtoBuiltFromCsv(): void
    {
        $model = new Model();
        $model->setCapabilities('chat,vision,tools');

        $set = $model->getCapabilitySet();

        self::assertInstanceOf(CapabilitySet::class, $set);
        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::VISION,
            ModelCapability::TOOLS,
        ], $set->capabilities);
    }

    #[Test]
    public function getCapabilitySetReturnsEmptyDtoWhenCsvIsEmpty(): void
    {
        $model = new Model();

        self::assertTrue($model->getCapabilitySet()->isEmpty());
    }

    #[Test]
    public function setCapabilitySetWritesCsv(): void
    {
        $model = new Model();
        $model->setCapabilitySet(CapabilitySet::fromArray([
            ModelCapability::CHAT,
            ModelCapability::EMBEDDINGS,
        ]));

        self::assertSame('chat,embeddings', $model->getCapabilities());
        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::EMBEDDINGS,
        ], $model->getCapabilitySet()->capabilities);
    }

    #[Test]
    public function setCapabilitySetSurvivesRoundTripThroughLegacyAccessors(): void
    {
        // Slice 16b will migrate the legacy accessors caller-by-caller;
        // until then the new typed setter must coexist with old string
        // and array readers without confusion.
        $model = new Model();
        $model->setCapabilitySet(CapabilitySet::fromArray([
            ModelCapability::CHAT,
            ModelCapability::VISION,
        ]));

        self::assertSame('chat,vision', $model->getCapabilities());
        self::assertSame(['chat', 'vision'], $model->getCapabilitiesArray());
        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::VISION,
        ], $model->getCapabilitiesAsEnums());
    }

    #[Test]
    public function getCapabilitiesAsEnumsDropsUnknownTokensButPreservesDuplicates(): void
    {
        // Regression guard: `getCapabilitiesAsEnums()` keeps its
        // pre-REC-#6 byte-for-byte semantics — drops unknown tokens
        // defensively, but PRESERVES duplicates if the persisted CSV
        // happens to carry them (the legacy setters do not dedupe).
        // Callers that want dedup should use `getCapabilitySet()`.
        $model = new Model();
        $model->setCapabilities('chat,unknown_obsolete_capability,tools,chat');

        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::TOOLS,
            ModelCapability::CHAT,
        ], $model->getCapabilitiesAsEnums());
    }

    #[Test]
    public function getCapabilitySetDeduplicatesPersistedDuplicates(): void
    {
        // Counterpart guard: `getCapabilitySet()` IS the dedup-aware
        // typed accessor; a CSV with duplicates must come back as a
        // single set entry per capability.
        $model = new Model();
        $model->setCapabilities('chat,tools,chat');

        self::assertSame([
            ModelCapability::CHAT,
            ModelCapability::TOOLS,
        ], $model->getCapabilitySet()->capabilities);
    }
}
