<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Model\AdapterType;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for Provider domain entity.
 *
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
final class ProviderTest extends AbstractUnitTestCase
{
    private Provider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new Provider();
    }

    // ========================================
    // Basic getter / setter tests
    // ========================================

    #[Test]
    public function identifierGetterAndSetter(): void
    {
        $this->subject->setIdentifier('openai-prod');
        self::assertSame('openai-prod', $this->subject->getIdentifier());
    }

    #[Test]
    public function nameGetterAndSetter(): void
    {
        $this->subject->setName('OpenAI Production');
        self::assertSame('OpenAI Production', $this->subject->getName());
    }

    #[Test]
    public function descriptionGetterAndSetter(): void
    {
        $this->subject->setDescription('Production OpenAI account');
        self::assertSame('Production OpenAI account', $this->subject->getDescription());
    }

    #[Test]
    public function adapterTypeGetterAndSetter(): void
    {
        $this->subject->setAdapterType('openai');
        self::assertSame('openai', $this->subject->getAdapterType());
    }

    #[Test]
    public function adapterTypeEnumSetterStoresValue(): void
    {
        $this->subject->setAdapterTypeEnum(AdapterType::Anthropic);
        self::assertSame('anthropic', $this->subject->getAdapterType());
    }

    #[Test]
    public function adapterTypeEnumGetterReturnsEnumInstance(): void
    {
        $this->subject->setAdapterType('gemini');
        self::assertSame(AdapterType::Gemini, $this->subject->getAdapterTypeEnum());
    }

    #[Test]
    public function adapterTypeEnumGetterReturnsNullForUnknownType(): void
    {
        $this->subject->setAdapterType('unknown-adapter');
        self::assertNull($this->subject->getAdapterTypeEnum());
    }

    #[Test]
    public function endpointUrlGetterAndSetter(): void
    {
        $this->subject->setEndpointUrl('https://custom.api.example.com/v1');
        self::assertSame('https://custom.api.example.com/v1', $this->subject->getEndpointUrl());
    }

    #[Test]
    public function organizationIdGetterAndSetter(): void
    {
        $this->subject->setOrganizationId('org-abc123');
        self::assertSame('org-abc123', $this->subject->getOrganizationId());
    }

    #[Test]
    public function isActiveGetterAndSetter(): void
    {
        $this->subject->setIsActive(false);
        self::assertFalse($this->subject->getIsActive());
        self::assertFalse($this->subject->isActive());
    }

    #[Test]
    public function isActiveDefaultsToTrue(): void
    {
        self::assertTrue($this->subject->isActive());
    }

    #[Test]
    public function sortingGetterAndSetter(): void
    {
        $this->subject->setSorting(42);
        self::assertSame(42, $this->subject->getSorting());
    }

    #[Test]
    public function optionsGetterAndSetter(): void
    {
        $json = '{"proxy":"http://proxy.example.com"}';
        $this->subject->setOptions($json);
        self::assertSame($json, $this->subject->getOptions());
    }

    // ========================================
    // setApiTimeout clamping
    // ========================================

    #[Test]
    public function setApiTimeoutStoresPositiveValue(): void
    {
        $this->subject->setApiTimeout(60);
        self::assertSame(60, $this->subject->getApiTimeout());
    }

    #[Test]
    public function setApiTimeoutClampsToOneForZero(): void
    {
        $this->subject->setApiTimeout(0);
        self::assertSame(1, $this->subject->getApiTimeout());
    }

    #[Test]
    public function setApiTimeoutClampsToOneForNegative(): void
    {
        $this->subject->setApiTimeout(-10);
        self::assertSame(1, $this->subject->getApiTimeout());
    }

    #[Test]
    public function setTimeoutAliasWorksLikeSetApiTimeout(): void
    {
        $this->subject->setTimeout(120);
        self::assertSame(120, $this->subject->getApiTimeout());
        self::assertSame(120, $this->subject->getTimeout());
    }

    // ========================================
    // setMaxRetries clamping
    // ========================================

    #[Test]
    public function setMaxRetriesStoresPositiveValue(): void
    {
        $this->subject->setMaxRetries(5);
        self::assertSame(5, $this->subject->getMaxRetries());
    }

    #[Test]
    public function setMaxRetriesClampsToZeroForNegative(): void
    {
        $this->subject->setMaxRetries(-1);
        self::assertSame(0, $this->subject->getMaxRetries());
    }

    #[Test]
    public function setMaxRetriesAllowsZero(): void
    {
        $this->subject->setMaxRetries(0);
        self::assertSame(0, $this->subject->getMaxRetries());
    }

    // ========================================
    // setPriority clamping (0–100)
    // ========================================

    #[Test]
    public function setPriorityStoresValueInRange(): void
    {
        $this->subject->setPriority(75);
        self::assertSame(75, $this->subject->getPriority());
    }

    #[Test]
    public function setPriorityClampsToZeroForNegative(): void
    {
        $this->subject->setPriority(-5);
        self::assertSame(0, $this->subject->getPriority());
    }

    #[Test]
    public function setPriorityClampsToHundredForOverflow(): void
    {
        $this->subject->setPriority(150);
        self::assertSame(100, $this->subject->getPriority());
    }

    #[Test]
    public function setPriorityAllowsBoundaryValues(): void
    {
        $this->subject->setPriority(0);
        self::assertSame(0, $this->subject->getPriority());

        $this->subject->setPriority(100);
        self::assertSame(100, $this->subject->getPriority());
    }

    // ========================================
    // setApiKey validation
    // ========================================

    #[Test]
    public function setApiKeyAcceptsEmptyString(): void
    {
        $this->subject->setApiKey('');
        self::assertSame('', $this->subject->getApiKey());
    }

    #[Test]
    public function setApiKeyAcceptsValidUuidV7(): void
    {
        // UUID v7 format: 8-4-4-4-12 with version digit 7
        $vaultId = '01938f2a-1b2c-7abc-89de-1234567890ab';
        $this->subject->setApiKey($vaultId);
        self::assertSame($vaultId, $this->subject->getApiKey());
    }

    #[Test]
    public function setApiKeyThrowsForRawApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key must be a vault identifier');

        // Raw API key, not a UUID v7
        $this->subject->setApiKey('sk-abc123xyz');
    }

    #[Test]
    public function setApiKeyThrowsForUuidV4(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // UUID v4 (version digit is 4, not 7)
        $this->subject->setApiKey('550e8400-e29b-41d4-a716-446655440000');
    }

    // ========================================
    // getOptionsArray
    // ========================================

    #[Test]
    public function getOptionsArrayReturnsEmptyArrayForEmptyString(): void
    {
        self::assertSame([], $this->subject->getOptionsArray());
    }

    #[Test]
    public function getOptionsArrayDecodesValidJson(): void
    {
        $this->subject->setOptions('{"proxy":"http://proxy.example.com","timeout":30}');
        $result = $this->subject->getOptionsArray();

        self::assertSame('http://proxy.example.com', $result['proxy']);
        self::assertSame(30, $result['timeout']);
    }

    #[Test]
    public function getOptionsArrayReturnsEmptyArrayForInvalidJson(): void
    {
        $this->subject->setOptions('not valid json');
        self::assertSame([], $this->subject->getOptionsArray());
    }

    #[Test]
    public function getOptionsArrayReturnsEmptyArrayForJsonScalar(): void
    {
        $this->subject->setOptions('"just a string"');
        self::assertSame([], $this->subject->getOptionsArray());
    }

    #[Test]
    public function setOptionsArrayEncodesArrayToJson(): void
    {
        $options = ['key' => 'value', 'number' => 42];
        $this->subject->setOptionsArray($options);

        $decoded = $this->subject->getOptionsArray();
        self::assertSame('value', $decoded['key']);
        self::assertSame(42, $decoded['number']);
    }

    // ========================================
    // getEffectiveEndpointUrl
    // ========================================

    #[Test]
    public function getEffectiveEndpointUrlReturnsCustomUrlWhenSet(): void
    {
        $customUrl = 'https://my-proxy.example.com/v1';
        $this->subject->setEndpointUrl($customUrl);
        $this->subject->setAdapterType('openai');

        self::assertSame($customUrl, $this->subject->getEffectiveEndpointUrl());
    }

    #[Test]
    public function getEffectiveEndpointUrlFallsBackToAdapterDefault(): void
    {
        // No custom endpoint
        $this->subject->setEndpointUrl('');
        $this->subject->setAdapterType('openai');

        self::assertSame('https://api.openai.com/v1', $this->subject->getEffectiveEndpointUrl());
    }

    #[Test]
    public function getEffectiveEndpointUrlReturnsEmptyForUnknownAdapter(): void
    {
        $this->subject->setEndpointUrl('');
        $this->subject->setAdapterType('unknown');

        self::assertSame('', $this->subject->getEffectiveEndpointUrl());
    }

    #[Test]
    public function hasCustomEndpointReturnsTrueWhenEndpointSet(): void
    {
        $this->subject->setEndpointUrl('https://custom.example.com');
        self::assertTrue($this->subject->hasCustomEndpoint());
    }

    #[Test]
    public function hasCustomEndpointReturnsFalseWhenEndpointEmpty(): void
    {
        $this->subject->setEndpointUrl('');
        self::assertFalse($this->subject->hasCustomEndpoint());
    }

    // ========================================
    // getDefaultEndpointForAdapter (static)
    // ========================================

    #[Test]
    public function getDefaultEndpointForAdapterAcceptsStringValue(): void
    {
        $endpoint = Provider::getDefaultEndpointForAdapter('anthropic');
        self::assertSame('https://api.anthropic.com/v1', $endpoint);
    }

    #[Test]
    public function getDefaultEndpointForAdapterAcceptsEnumInstance(): void
    {
        $endpoint = Provider::getDefaultEndpointForAdapter(AdapterType::Gemini);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta', $endpoint);
    }

    #[Test]
    public function getDefaultEndpointForAdapterReturnsEmptyStringForUnknown(): void
    {
        $endpoint = Provider::getDefaultEndpointForAdapter('nonexistent');
        self::assertSame('', $endpoint);
    }

    // ========================================
    // getAdapterName
    // ========================================

    #[Test]
    public function getAdapterNameReturnsHumanReadableLabel(): void
    {
        $this->subject->setAdapterType('openai');
        self::assertSame('OpenAI', $this->subject->getAdapterName());
    }

    #[Test]
    public function getAdapterNameFallsBackToRawTypeForUnknown(): void
    {
        $this->subject->setAdapterType('custom-unknown-type');
        self::assertSame('custom-unknown-type', $this->subject->getAdapterName());
    }

    // ========================================
    // getAdapterTypes (static)
    // ========================================

    #[Test]
    public function getAdapterTypesReturnsNonEmptyArray(): void
    {
        $types = Provider::getAdapterTypes();
        self::assertNotEmpty($types);
        self::assertArrayHasKey('openai', $types);
        self::assertArrayHasKey('anthropic', $types);
    }

    // ========================================
    // toAdapterConfig
    // ========================================

    #[Test]
    public function toAdapterConfigContainsRequiredKeys(): void
    {
        $this->subject->setAdapterType('openai');
        $this->subject->setApiTimeout(45);
        $this->subject->setMaxRetries(2);

        $config = $this->subject->toAdapterConfig();

        self::assertArrayHasKey('api_key', $config);
        self::assertArrayHasKey('endpoint', $config);
        self::assertArrayHasKey('api_timeout', $config);
        self::assertArrayHasKey('max_retries', $config);
    }

    #[Test]
    public function toAdapterConfigIncludesOrganizationIdWhenSet(): void
    {
        $this->subject->setOrganizationId('org-test');
        $config = $this->subject->toAdapterConfig();

        self::assertArrayHasKey('organization_id', $config);
        self::assertSame('org-test', $config['organization_id']);
    }

    #[Test]
    public function toAdapterConfigOmitsOrganizationIdWhenEmpty(): void
    {
        $this->subject->setOrganizationId('');
        $config = $this->subject->toAdapterConfig();

        self::assertArrayNotHasKey('organization_id', $config);
    }

    #[Test]
    public function toAdapterConfigMergesAdditionalOptions(): void
    {
        $this->subject->setOptionsArray(['custom_param' => 'custom_value']);
        $config = $this->subject->toAdapterConfig();

        self::assertArrayHasKey('custom_param', $config);
        self::assertSame('custom_value', $config['custom_param']);
    }

    #[Test]
    public function toAdapterConfigUsesEffectiveEndpoint(): void
    {
        $this->subject->setAdapterType('mistral');
        $this->subject->setEndpointUrl('');

        $config = $this->subject->toAdapterConfig();

        self::assertSame('https://api.mistral.ai/v1', $config['endpoint']);
    }

    #[Test]
    public function toAdapterConfigApiTimeoutMatchesSetValue(): void
    {
        $this->subject->setApiTimeout(90);
        $config = $this->subject->toAdapterConfig();

        self::assertSame(90, $config['api_timeout']);
    }

    // ========================================
    // Defaults
    // ========================================

    #[Test]
    public function defaultApiTimeoutIsThirtySeconds(): void
    {
        self::assertSame(30, $this->subject->getApiTimeout());
    }

    #[Test]
    public function defaultMaxRetriesIsThree(): void
    {
        self::assertSame(3, $this->subject->getMaxRetries());
    }

    #[Test]
    public function defaultPriorityIsFifty(): void
    {
        self::assertSame(50, $this->subject->getPriority());
    }

    #[Test]
    public function modelsObjectStorageInitializedInConstructor(): void
    {
        self::assertNotNull($this->subject->getModels());
    }

    // ========================================
    // Provider-specific defaults
    // ========================================

    /**
     * @return array<string, array{string, string}>
     */
    public static function adapterDefaultEndpointProvider(): array
    {
        return [
            'openai' => ['openai', 'https://api.openai.com/v1'],
            'anthropic' => ['anthropic', 'https://api.anthropic.com/v1'],
            'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta'],
            'openrouter' => ['openrouter', 'https://openrouter.ai/api/v1'],
            'mistral' => ['mistral', 'https://api.mistral.ai/v1'],
            'groq' => ['groq', 'https://api.groq.com/openai/v1'],
            'ollama' => ['ollama', 'http://localhost:11434/api'],
        ];
    }

    #[Test]
    #[DataProvider('adapterDefaultEndpointProvider')]
    public function getEffectiveEndpointUrlReturnsCorrectDefaultPerAdapter(
        string $adapterType,
        string $expectedEndpoint,
    ): void {
        $this->subject->setEndpointUrl('');
        $this->subject->setAdapterType($adapterType);

        self::assertSame($expectedEndpoint, $this->subject->getEffectiveEndpointUrl());
    }
}
