<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Enum\ModelSelectionMode;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for LlmConfiguration domain entity.
 *
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
final class LlmConfigurationTest extends AbstractUnitTestCase
{
    private LlmConfiguration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new LlmConfiguration();
    }

    // ========================================
    // Basic getter / setter tests
    // ========================================

    #[Test]
    public function identifierGetterAndSetter(): void
    {
        $this->subject->setIdentifier('gpt4-production');
        self::assertSame('gpt4-production', $this->subject->getIdentifier());
    }

    #[Test]
    public function nameGetterAndSetter(): void
    {
        $this->subject->setName('GPT-4 Production');
        self::assertSame('GPT-4 Production', $this->subject->getName());
    }

    #[Test]
    public function descriptionGetterAndSetter(): void
    {
        $this->subject->setDescription('Production GPT-4 configuration');
        self::assertSame('Production GPT-4 configuration', $this->subject->getDescription());
    }

    #[Test]
    public function systemPromptGetterAndSetter(): void
    {
        $this->subject->setSystemPrompt('You are a helpful assistant.');
        self::assertSame('You are a helpful assistant.', $this->subject->getSystemPrompt());
    }

    #[Test]
    public function translatorGetterAndSetter(): void
    {
        $this->subject->setTranslator('deepl');
        self::assertSame('deepl', $this->subject->getTranslator());
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
    public function isDefaultGetterAndSetter(): void
    {
        $this->subject->setIsDefault(true);
        self::assertTrue($this->subject->getIsDefault());
        self::assertTrue($this->subject->isDefault());
    }

    #[Test]
    public function isDefaultDefaultsToFalse(): void
    {
        self::assertFalse($this->subject->isDefault());
    }

    // ========================================
    // Temperature clamping (0.0–2.0)
    // ========================================

    #[Test]
    public function setTemperatureStoresValueInRange(): void
    {
        $this->subject->setTemperature(1.5);
        self::assertSame(1.5, $this->subject->getTemperature());
    }

    #[Test]
    public function setTemperatureClampsToZeroForNegative(): void
    {
        $this->subject->setTemperature(-0.5);
        self::assertSame(0.0, $this->subject->getTemperature());
    }

    #[Test]
    public function setTemperatureClampsTwoForOverflow(): void
    {
        $this->subject->setTemperature(3.0);
        self::assertSame(2.0, $this->subject->getTemperature());
    }

    #[Test]
    public function setTemperatureAllowsBoundaryValues(): void
    {
        $this->subject->setTemperature(0.0);
        self::assertSame(0.0, $this->subject->getTemperature());

        $this->subject->setTemperature(2.0);
        self::assertSame(2.0, $this->subject->getTemperature());
    }

    // ========================================
    // MaxTokens clamping (minimum 1)
    // ========================================

    #[Test]
    public function setMaxTokensStoresPositiveValue(): void
    {
        $this->subject->setMaxTokens(4096);
        self::assertSame(4096, $this->subject->getMaxTokens());
    }

    #[Test]
    public function setMaxTokensClampsToOneForZero(): void
    {
        $this->subject->setMaxTokens(0);
        self::assertSame(1, $this->subject->getMaxTokens());
    }

    #[Test]
    public function setMaxTokensClampsToOneForNegative(): void
    {
        $this->subject->setMaxTokens(-100);
        self::assertSame(1, $this->subject->getMaxTokens());
    }

    // ========================================
    // TopP clamping (0.0–1.0)
    // ========================================

    #[Test]
    public function setTopPStoresValueInRange(): void
    {
        $this->subject->setTopP(0.9);
        self::assertSame(0.9, $this->subject->getTopP());
    }

    #[Test]
    public function setTopPClampsToZeroForNegative(): void
    {
        $this->subject->setTopP(-0.1);
        self::assertSame(0.0, $this->subject->getTopP());
    }

    #[Test]
    public function setTopPClampsToOneForOverflow(): void
    {
        $this->subject->setTopP(1.5);
        self::assertSame(1.0, $this->subject->getTopP());
    }

    // ========================================
    // FrequencyPenalty clamping (-2.0–2.0)
    // ========================================

    #[Test]
    public function setFrequencyPenaltyStoresValueInRange(): void
    {
        $this->subject->setFrequencyPenalty(1.0);
        self::assertSame(1.0, $this->subject->getFrequencyPenalty());
    }

    #[Test]
    public function setFrequencyPenaltyClampsToNegativeTwoForUnderflow(): void
    {
        $this->subject->setFrequencyPenalty(-3.0);
        self::assertSame(-2.0, $this->subject->getFrequencyPenalty());
    }

    #[Test]
    public function setFrequencyPenaltyClampsTwoForOverflow(): void
    {
        $this->subject->setFrequencyPenalty(3.0);
        self::assertSame(2.0, $this->subject->getFrequencyPenalty());
    }

    // ========================================
    // PresencePenalty clamping (-2.0–2.0)
    // ========================================

    #[Test]
    public function setPresencePenaltyStoresValueInRange(): void
    {
        $this->subject->setPresencePenalty(-1.0);
        self::assertSame(-1.0, $this->subject->getPresencePenalty());
    }

    #[Test]
    public function setPresencePenaltyClampsForOutOfRange(): void
    {
        $this->subject->setPresencePenalty(5.0);
        self::assertSame(2.0, $this->subject->getPresencePenalty());
    }

    // ========================================
    // setTimeout clamping (minimum 0)
    // ========================================

    #[Test]
    public function setTimeoutStoresPositiveValue(): void
    {
        $this->subject->setTimeout(120);
        self::assertSame(120, $this->subject->getTimeout());
    }

    #[Test]
    public function setTimeoutClampsToZeroForNegative(): void
    {
        $this->subject->setTimeout(-10);
        self::assertSame(0, $this->subject->getTimeout());
    }

    // ========================================
    // getEffectiveTimeout
    // ========================================

    #[Test]
    public function getEffectiveTimeoutReturnsTimeoutWhenPositive(): void
    {
        $this->subject->setTimeout(180);
        self::assertSame(180, $this->subject->getEffectiveTimeout());
    }

    #[Test]
    public function getEffectiveTimeoutFallsBackToModelDefaultWhenTimeoutIsZero(): void
    {
        $this->subject->setTimeout(0);

        $model = self::createStub(Model::class);
        $model->method('getDefaultTimeout')->willReturn(90);
        $this->subject->setLlmModel($model);

        self::assertSame(90, $this->subject->getEffectiveTimeout());
    }

    #[Test]
    public function getEffectiveTimeoutFallsBackToHardcodedDefaultWhenNoModelAndTimeoutZero(): void
    {
        $this->subject->setTimeout(0);
        $this->subject->setLlmModel(null);

        self::assertSame(120, $this->subject->getEffectiveTimeout());
    }

    // ========================================
    // Usage limits
    // ========================================

    #[Test]
    public function setMaxRequestsPerDayClampsToZeroForNegative(): void
    {
        $this->subject->setMaxRequestsPerDay(-5);
        self::assertSame(0, $this->subject->getMaxRequestsPerDay());
    }

    #[Test]
    public function setMaxTokensPerDayClampsToZeroForNegative(): void
    {
        $this->subject->setMaxTokensPerDay(-100);
        self::assertSame(0, $this->subject->getMaxTokensPerDay());
    }

    #[Test]
    public function setMaxCostPerDayClampsToZeroForNegative(): void
    {
        $this->subject->setMaxCostPerDay(-1.0);
        self::assertSame(0.0, $this->subject->getMaxCostPerDay());
    }

    #[Test]
    public function hasUsageLimitsReturnsFalseWhenAllZero(): void
    {
        self::assertFalse($this->subject->hasUsageLimits());
    }

    #[Test]
    public function hasUsageLimitsReturnsTrueWhenMaxRequestsSet(): void
    {
        $this->subject->setMaxRequestsPerDay(100);
        self::assertTrue($this->subject->hasUsageLimits());
    }

    #[Test]
    public function hasUsageLimitsReturnsTrueWhenMaxTokensSet(): void
    {
        $this->subject->setMaxTokensPerDay(10000);
        self::assertTrue($this->subject->hasUsageLimits());
    }

    #[Test]
    public function hasUsageLimitsReturnsTrueWhenMaxCostSet(): void
    {
        $this->subject->setMaxCostPerDay(5.0);
        self::assertTrue($this->subject->hasUsageLimits());
    }

    // ========================================
    // getOptionsArray / setOptionsArray
    // ========================================

    #[Test]
    public function getOptionsArrayReturnsEmptyArrayWhenNotSet(): void
    {
        self::assertSame([], $this->subject->getOptionsArray());
    }

    #[Test]
    public function getOptionsArrayDecodesValidJson(): void
    {
        $this->subject->setOptions('{"custom_key":"custom_value","number":42}');
        $result = $this->subject->getOptionsArray();

        self::assertSame('custom_value', $result['custom_key']);
        self::assertSame(42, $result['number']);
    }

    #[Test]
    public function getOptionsArrayReturnsEmptyForInvalidJson(): void
    {
        $this->subject->setOptions('invalid json');
        self::assertSame([], $this->subject->getOptionsArray());
    }

    #[Test]
    public function setOptionsArrayEncodesAndRoundTrips(): void
    {
        $options = ['param1' => 'value1', 'param2' => 99];
        $this->subject->setOptionsArray($options);

        $result = $this->subject->getOptionsArray();
        self::assertSame('value1', $result['param1']);
        self::assertSame(99, $result['param2']);
    }

    // ========================================
    // ModelSelectionMode
    // ========================================

    #[Test]
    public function setModelSelectionModeStoresValidMode(): void
    {
        $this->subject->setModelSelectionMode('criteria');
        self::assertSame('criteria', $this->subject->getModelSelectionMode());
    }

    #[Test]
    public function setModelSelectionModeFallsBackToFixedForInvalidValue(): void
    {
        $this->subject->setModelSelectionMode('invalid_mode');
        self::assertSame(ModelSelectionMode::FIXED->value, $this->subject->getModelSelectionMode());
    }

    #[Test]
    public function getModelSelectionModeEnumReturnsCorrectEnum(): void
    {
        $this->subject->setModelSelectionMode('criteria');
        self::assertSame(ModelSelectionMode::CRITERIA, $this->subject->getModelSelectionModeEnum());
    }

    #[Test]
    public function usesCriteriaSelectionReturnsTrueForCriteriaMode(): void
    {
        $this->subject->setModelSelectionMode('criteria');
        self::assertTrue($this->subject->usesCriteriaSelection());
    }

    #[Test]
    public function usesCriteriaSelectionReturnsFalseForFixedMode(): void
    {
        $this->subject->setModelSelectionMode('fixed');
        self::assertFalse($this->subject->usesCriteriaSelection());
    }

    // ========================================
    // getModelSelectionCriteriaArray
    // ========================================

    #[Test]
    public function getModelSelectionCriteriaArrayReturnsEmptyWhenNotSet(): void
    {
        self::assertSame([], $this->subject->getModelSelectionCriteriaArray());
    }

    #[Test]
    public function getModelSelectionCriteriaArrayDecodesValidJson(): void
    {
        $this->subject->setModelSelectionCriteria('{"capabilities":["chat","vision"],"minContextLength":4096}');
        $result = $this->subject->getModelSelectionCriteriaArray();

        self::assertTrue(isset($result['capabilities']));
        self::assertSame(['chat', 'vision'], $result['capabilities']);
        self::assertTrue(isset($result['minContextLength']));
        self::assertSame(4096, $result['minContextLength']);
    }

    #[Test]
    public function setModelSelectionCriteriaArrayEncodesAndRoundTrips(): void
    {
        $criteria = ['capabilities' => ['chat'], 'preferLowestCost' => true];
        $this->subject->setModelSelectionCriteriaArray($criteria);

        $result = $this->subject->getModelSelectionCriteriaArray();
        self::assertTrue(isset($result['capabilities']));
        self::assertSame(['chat'], $result['capabilities']);
        self::assertTrue(isset($result['preferLowestCost']));
        self::assertTrue($result['preferLowestCost']);
    }

    // ========================================
    // hasLlmModel / getLlmModel
    // ========================================

    #[Test]
    public function hasLlmModelReturnsFalseWhenNotSet(): void
    {
        self::assertFalse($this->subject->hasLlmModel());
    }

    #[Test]
    public function hasLlmModelReturnsTrueWhenModelSet(): void
    {
        $model = self::createStub(Model::class);
        $this->subject->setLlmModel($model);
        self::assertTrue($this->subject->hasLlmModel());
    }

    #[Test]
    public function setLlmModelWithNullClearsModel(): void
    {
        $model = self::createStub(Model::class);
        $this->subject->setLlmModel($model);
        $this->subject->setLlmModel(null);

        self::assertFalse($this->subject->hasLlmModel());
    }

    // ========================================
    // getProviderType / getModelId
    // ========================================

    #[Test]
    public function getProviderTypeReturnsEmptyStringWhenNoModel(): void
    {
        self::assertSame('', $this->subject->getProviderType());
    }

    #[Test]
    public function getProviderTypeReturnsEmptyStringWhenModelHasNoProvider(): void
    {
        $model = self::createStub(Model::class);
        $model->method('getProvider')->willReturn(null);
        $this->subject->setLlmModel($model);

        self::assertSame('', $this->subject->getProviderType());
    }

    #[Test]
    public function getProviderTypeReturnsAdapterTypeFromProvider(): void
    {
        $provider = self::createStub(Provider::class);
        $provider->method('getAdapterType')->willReturn('anthropic');

        $model = self::createStub(Model::class);
        $model->method('getProvider')->willReturn($provider);
        $this->subject->setLlmModel($model);

        self::assertSame('anthropic', $this->subject->getProviderType());
    }

    #[Test]
    public function getModelIdReturnsEmptyStringWhenNoModel(): void
    {
        self::assertSame('', $this->subject->getModelId());
    }

    #[Test]
    public function getModelIdReturnsModelIdFromModel(): void
    {
        $model = self::createStub(Model::class);
        $model->method('getModelId')->willReturn('claude-sonnet-4-20250514');
        $this->subject->setLlmModel($model);

        self::assertSame('claude-sonnet-4-20250514', $this->subject->getModelId());
    }

    // ========================================
    // toChatOptions
    // ========================================

    #[Test]
    public function toChatOptionsReturnsChatOptionsInstance(): void
    {
        self::assertInstanceOf(ChatOptions::class, $this->subject->toChatOptions());
    }

    #[Test]
    public function toChatOptionsMapsTemperature(): void
    {
        $this->subject->setTemperature(0.5);
        $options = $this->subject->toChatOptions();

        self::assertSame(0.5, $options->getTemperature());
    }

    #[Test]
    public function toChatOptionsMapsMaxTokens(): void
    {
        $this->subject->setMaxTokens(2048);
        $options = $this->subject->toChatOptions();

        self::assertSame(2048, $options->getMaxTokens());
    }

    #[Test]
    public function toChatOptionsMapsSystemPromptWhenNonEmpty(): void
    {
        $this->subject->setSystemPrompt('You are a test assistant.');
        $options = $this->subject->toChatOptions();

        self::assertSame('You are a test assistant.', $options->getSystemPrompt());
    }

    #[Test]
    public function toChatOptionsSystemPromptIsNullWhenEmpty(): void
    {
        $this->subject->setSystemPrompt('');
        $options = $this->subject->toChatOptions();

        self::assertNull($options->getSystemPrompt());
    }

    #[Test]
    public function toChatOptionsMapsProviderWhenModelAndProviderSet(): void
    {
        $provider = self::createStub(Provider::class);
        $provider->method('getAdapterType')->willReturn('openai');

        $model = self::createStub(Model::class);
        $model->method('getProvider')->willReturn($provider);
        $model->method('getModelId')->willReturn('gpt-4o');
        $this->subject->setLlmModel($model);

        $options = $this->subject->toChatOptions();

        self::assertSame('openai', $options->getProvider());
        self::assertSame('gpt-4o', $options->getModel());
    }

    #[Test]
    public function toChatOptionsProviderIsNullWhenNoModel(): void
    {
        $options = $this->subject->toChatOptions();

        self::assertNull($options->getProvider());
        self::assertNull($options->getModel());
    }

    // ========================================
    // toOptionsArray
    // ========================================

    #[Test]
    public function toOptionsArrayContainsAllBaseKeys(): void
    {
        $options = $this->subject->toOptionsArray();

        self::assertArrayHasKey('temperature', $options);
        self::assertArrayHasKey('max_tokens', $options);
        self::assertArrayHasKey('top_p', $options);
        self::assertArrayHasKey('frequency_penalty', $options);
        self::assertArrayHasKey('presence_penalty', $options);
        self::assertArrayHasKey('timeout', $options);
    }

    #[Test]
    public function toOptionsArrayIncludesSystemPromptWhenNonEmpty(): void
    {
        $this->subject->setSystemPrompt('System instructions here.');
        $options = $this->subject->toOptionsArray();

        self::assertArrayHasKey('system_prompt', $options);
        self::assertSame('System instructions here.', $options['system_prompt']);
    }

    #[Test]
    public function toOptionsArrayOmitsSystemPromptWhenEmpty(): void
    {
        $this->subject->setSystemPrompt('');
        $options = $this->subject->toOptionsArray();

        self::assertArrayNotHasKey('system_prompt', $options);
    }

    #[Test]
    public function toOptionsArrayIncludesTranslatorWhenSet(): void
    {
        $this->subject->setTranslator('deepl');
        $options = $this->subject->toOptionsArray();

        self::assertArrayHasKey('translator', $options);
        self::assertSame('deepl', $options['translator']);
    }

    #[Test]
    public function toOptionsArrayMergesAdditionalOptions(): void
    {
        $this->subject->setOptionsArray(['extra_param' => 'extra_value']);
        $options = $this->subject->toOptionsArray();

        self::assertArrayHasKey('extra_param', $options);
        self::assertSame('extra_value', $options['extra_param']);
    }

    #[Test]
    public function toOptionsArrayTemperatureMatchesSetValue(): void
    {
        $this->subject->setTemperature(0.3);
        $options = $this->subject->toOptionsArray();

        self::assertSame(0.3, $options['temperature']);
    }

    // ========================================
    // hasAccessRestrictions
    // ========================================

    #[Test]
    public function hasAccessRestrictionsReturnsFalseWhenNoRestrictions(): void
    {
        self::assertFalse($this->subject->hasAccessRestrictions());
    }

    #[Test]
    public function hasAccessRestrictionsReturnsTrueWhenAllowedGroupsSet(): void
    {
        $this->subject->setAllowedGroups(1);
        self::assertTrue($this->subject->hasAccessRestrictions());
    }

    // ========================================
    // Default values
    // ========================================

    #[Test]
    public function defaultTemperatureIsSevenTenths(): void
    {
        self::assertSame(0.7, $this->subject->getTemperature());
    }

    #[Test]
    public function defaultMaxTokensIsThousand(): void
    {
        self::assertSame(1000, $this->subject->getMaxTokens());
    }

    #[Test]
    public function defaultTopPIsOne(): void
    {
        self::assertSame(1.0, $this->subject->getTopP());
    }

    #[Test]
    public function defaultModelSelectionModeIsFixed(): void
    {
        self::assertSame('fixed', $this->subject->getModelSelectionMode());
    }

    #[Test]
    public function beGroupsStorageInitializedInConstructor(): void
    {
        self::assertNotNull($this->subject->getBeGroups());
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function temperatureClampingProvider(): array
    {
        return [
            'zero stays zero' => [0.0, 0.0],
            'two stays two' => [2.0, 2.0],
            'one stays one' => [1.0, 1.0],
            'negative clamped to zero' => [-1.0, 0.0],
            'over two clamped to two' => [2.5, 2.0],
        ];
    }

    #[Test]
    #[DataProvider('temperatureClampingProvider')]
    public function temperatureClampingBehavior(float $input, float $expected): void
    {
        $this->subject->setTemperature($input);
        self::assertSame($expected, $this->subject->getTemperature());
    }
}
