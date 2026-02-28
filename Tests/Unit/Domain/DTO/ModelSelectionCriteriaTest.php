<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\DTO;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ModelSelectionCriteria::class)]
class ModelSelectionCriteriaTest extends AbstractUnitTestCase
{
    // ──────────────────────────────────────────────
    // Constructor & defaults
    // ──────────────────────────────────────────────

    #[Test]
    public function constructorUsesDefaults(): void
    {
        $criteria = new ModelSelectionCriteria();

        self::assertSame([], $criteria->capabilities);
        self::assertSame([], $criteria->adapterTypes);
        self::assertSame(0, $criteria->minContextLength);
        self::assertSame(0, $criteria->maxCostInput);
        self::assertFalse($criteria->preferLowestCost);
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $capabilities = ['chat', 'vision'];
        $adapterTypes = ['openai', 'anthropic'];

        $criteria = new ModelSelectionCriteria(
            capabilities: $capabilities,
            adapterTypes: $adapterTypes,
            minContextLength: 8192,
            maxCostInput: 500,
            preferLowestCost: true,
        );

        self::assertSame($capabilities, $criteria->capabilities);
        self::assertSame($adapterTypes, $criteria->adapterTypes);
        self::assertSame(8192, $criteria->minContextLength);
        self::assertSame(500, $criteria->maxCostInput);
        self::assertTrue($criteria->preferLowestCost);
    }

    // ──────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────

    #[Test]
    public function fromArrayCreatesInstanceWithAllFields(): void
    {
        $data = [
            'capabilities' => ['chat', 'embeddings'],
            'adapterTypes' => ['openai'],
            'minContextLength' => 4096,
            'maxCostInput' => 200,
            'preferLowestCost' => true,
        ];

        $criteria = ModelSelectionCriteria::fromArray($data);

        self::assertSame(['chat', 'embeddings'], $criteria->capabilities);
        self::assertSame(['openai'], $criteria->adapterTypes);
        self::assertSame(4096, $criteria->minContextLength);
        self::assertSame(200, $criteria->maxCostInput);
        self::assertTrue($criteria->preferLowestCost);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingKeys(): void
    {
        $criteria = ModelSelectionCriteria::fromArray([]);

        self::assertSame([], $criteria->capabilities);
        self::assertSame([], $criteria->adapterTypes);
        self::assertSame(0, $criteria->minContextLength);
        self::assertSame(0, $criteria->maxCostInput);
        self::assertFalse($criteria->preferLowestCost);
    }

    #[Test]
    public function fromArrayHandlesPartialData(): void
    {
        $criteria = ModelSelectionCriteria::fromArray([
            'capabilities' => ['vision'],
            'maxCostInput' => 100,
        ]);

        self::assertSame(['vision'], $criteria->capabilities);
        self::assertSame([], $criteria->adapterTypes);
        self::assertSame(0, $criteria->minContextLength);
        self::assertSame(100, $criteria->maxCostInput);
        self::assertFalse($criteria->preferLowestCost);
    }

    // ──────────────────────────────────────────────
    // fromJson
    // ──────────────────────────────────────────────

    #[Test]
    public function fromJsonCreatesInstanceFromValidJson(): void
    {
        $json = json_encode([
            'capabilities' => ['chat', 'tools'],
            'adapterTypes' => ['gemini'],
            'minContextLength' => 16384,
            'maxCostInput' => 300,
            'preferLowestCost' => false,
        ], JSON_THROW_ON_ERROR);

        $criteria = ModelSelectionCriteria::fromJson($json);

        self::assertSame(['chat', 'tools'], $criteria->capabilities);
        self::assertSame(['gemini'], $criteria->adapterTypes);
        self::assertSame(16384, $criteria->minContextLength);
        self::assertSame(300, $criteria->maxCostInput);
        self::assertFalse($criteria->preferLowestCost);
    }

    #[Test]
    public function fromJsonReturnsDefaultsForEmptyString(): void
    {
        $criteria = ModelSelectionCriteria::fromJson('');

        self::assertSame([], $criteria->capabilities);
        self::assertSame([], $criteria->adapterTypes);
        self::assertSame(0, $criteria->minContextLength);
        self::assertSame(0, $criteria->maxCostInput);
        self::assertFalse($criteria->preferLowestCost);
    }

    #[Test]
    public function fromJsonReturnsDefaultsForInvalidJson(): void
    {
        $criteria = ModelSelectionCriteria::fromJson('not valid json');

        self::assertSame([], $criteria->capabilities);
        self::assertSame([], $criteria->adapterTypes);
        self::assertSame(0, $criteria->minContextLength);
        self::assertFalse($criteria->preferLowestCost);
    }

    #[Test]
    public function fromJsonReturnsDefaultsForNonArrayJson(): void
    {
        $criteria = ModelSelectionCriteria::fromJson('"just a string"');

        self::assertSame([], $criteria->capabilities);
        self::assertFalse($criteria->preferLowestCost);
    }

    #[Test]
    public function fromJsonReturnsDefaultsForJsonNull(): void
    {
        $criteria = ModelSelectionCriteria::fromJson('null');

        self::assertSame([], $criteria->capabilities);
        self::assertFalse($criteria->preferLowestCost);
    }

    // ──────────────────────────────────────────────
    // toArray / toJson / jsonSerialize
    // ──────────────────────────────────────────────

    #[Test]
    public function toArrayReturnsAllProperties(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['chat'],
            adapterTypes: ['openai'],
            minContextLength: 2048,
            maxCostInput: 150,
            preferLowestCost: true,
        );

        $expected = [
            'capabilities' => ['chat'],
            'adapterTypes' => ['openai'],
            'minContextLength' => 2048,
            'maxCostInput' => 150,
            'preferLowestCost' => true,
        ];

        self::assertSame($expected, $criteria->toArray());
    }

    #[Test]
    public function toJsonReturnsValidJsonString(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['vision'],
        );

        $json = $criteria->toJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['vision'], $decoded['capabilities']);
        self::assertSame([], $decoded['adapterTypes']);
        self::assertSame(0, $decoded['minContextLength']);
    }

    #[Test]
    public function jsonSerializeReturnsToArrayResult(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['chat'],
            adapterTypes: ['anthropic'],
            minContextLength: 4096,
        );

        self::assertSame($criteria->toArray(), $criteria->jsonSerialize());
    }

    #[Test]
    public function jsonEncodeUsesJsonSerialize(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['embeddings'],
            preferLowestCost: true,
        );

        $json = json_encode($criteria, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['embeddings'], $decoded['capabilities']);
        self::assertTrue($decoded['preferLowestCost']);
    }

    #[Test]
    public function fromArrayAndToArrayRoundTrip(): void
    {
        $data = [
            'capabilities' => ['chat', 'vision', 'tools'],
            'adapterTypes' => ['openai', 'anthropic'],
            'minContextLength' => 32768,
            'maxCostInput' => 1000,
            'preferLowestCost' => true,
        ];

        $criteria = ModelSelectionCriteria::fromArray($data);

        self::assertSame($data, $criteria->toArray());
    }

    #[Test]
    public function fromJsonAndToJsonRoundTrip(): void
    {
        $original = new ModelSelectionCriteria(
            capabilities: ['streaming', 'json_mode'],
            adapterTypes: ['gemini'],
            minContextLength: 8192,
            maxCostInput: 250,
            preferLowestCost: false,
        );

        $json = $original->toJson();
        $restored = ModelSelectionCriteria::fromJson($json);

        self::assertSame($original->toArray(), $restored->toArray());
    }

    // ──────────────────────────────────────────────
    // hasCriteria
    // ──────────────────────────────────────────────

    #[Test]
    public function hasCriteriaReturnsFalseForDefaults(): void
    {
        $criteria = new ModelSelectionCriteria();

        self::assertFalse($criteria->hasCriteria());
    }

    /**
     * @param list<string> $capabilities
     * @param list<string> $adapterTypes
     */
    #[Test]
    #[DataProvider('hasCriteriaProvider')]
    public function hasCriteriaReturnsTrueWhenAnyFieldSet(
        array $capabilities,
        array $adapterTypes,
        int $minContextLength,
        int $maxCostInput,
    ): void {
        $criteria = new ModelSelectionCriteria(
            capabilities: $capabilities,
            adapterTypes: $adapterTypes,
            minContextLength: $minContextLength,
            maxCostInput: $maxCostInput,
        );

        self::assertTrue($criteria->hasCriteria());
    }

    /**
     * @return array<string, array{list<string>, list<string>, int, int}>
     */
    public static function hasCriteriaProvider(): array
    {
        return [
            'capabilities only' => [['chat'], [], 0, 0],
            'adapterTypes only' => [[], ['openai'], 0, 0],
            'minContextLength only' => [[], [], 4096, 0],
            'maxCostInput only' => [[], [], 0, 100],
            'all fields set' => [['vision'], ['anthropic'], 8192, 500],
        ];
    }

    #[Test]
    public function hasCriteriaIgnoresPreferLowestCost(): void
    {
        $criteria = new ModelSelectionCriteria(preferLowestCost: true);

        self::assertFalse($criteria->hasCriteria());
    }

    // ──────────────────────────────────────────────
    // requiresCapability
    // ──────────────────────────────────────────────

    #[Test]
    public function requiresCapabilityReturnsTrueForPresentCapabilityString(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['chat', 'vision'],
        );

        self::assertTrue($criteria->requiresCapability('chat'));
        self::assertTrue($criteria->requiresCapability('vision'));
    }

    #[Test]
    public function requiresCapabilityReturnsFalseForAbsentCapability(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['chat'],
        );

        self::assertFalse($criteria->requiresCapability('vision'));
        self::assertFalse($criteria->requiresCapability('embeddings'));
    }

    #[Test]
    public function requiresCapabilityReturnsFalseForEmptyCapabilities(): void
    {
        $criteria = new ModelSelectionCriteria();

        self::assertFalse($criteria->requiresCapability('chat'));
    }

    #[Test]
    public function requiresCapabilityAcceptsModelCapabilityEnum(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['chat', 'vision'],
        );

        self::assertTrue($criteria->requiresCapability(ModelCapability::CHAT));
        self::assertTrue($criteria->requiresCapability(ModelCapability::VISION));
        self::assertFalse($criteria->requiresCapability(ModelCapability::EMBEDDINGS));
    }

    // ──────────────────────────────────────────────
    // allowsAdapterType
    // ──────────────────────────────────────────────

    #[Test]
    public function allowsAdapterTypeReturnsTrueWhenNoRestriction(): void
    {
        $criteria = new ModelSelectionCriteria();

        self::assertTrue($criteria->allowsAdapterType('openai'));
        self::assertTrue($criteria->allowsAdapterType('anthropic'));
        self::assertTrue($criteria->allowsAdapterType('anything'));
    }

    #[Test]
    public function allowsAdapterTypeReturnsTrueForAllowedType(): void
    {
        $criteria = new ModelSelectionCriteria(
            adapterTypes: ['openai', 'anthropic'],
        );

        self::assertTrue($criteria->allowsAdapterType('openai'));
        self::assertTrue($criteria->allowsAdapterType('anthropic'));
    }

    #[Test]
    public function allowsAdapterTypeReturnsFalseForDisallowedType(): void
    {
        $criteria = new ModelSelectionCriteria(
            adapterTypes: ['openai'],
        );

        self::assertFalse($criteria->allowsAdapterType('anthropic'));
        self::assertFalse($criteria->allowsAdapterType('gemini'));
    }

    // ──────────────────────────────────────────────
    // withCapability
    // ──────────────────────────────────────────────

    #[Test]
    public function withCapabilityAddsNewCapabilityString(): void
    {
        $criteria = new ModelSelectionCriteria(capabilities: ['chat']);

        $updated = $criteria->withCapability('vision');

        self::assertSame(['chat', 'vision'], $updated->capabilities);
        // original is unchanged (immutable)
        self::assertSame(['chat'], $criteria->capabilities);
    }

    #[Test]
    public function withCapabilityReturnsSameInstanceForDuplicate(): void
    {
        $criteria = new ModelSelectionCriteria(capabilities: ['chat']);

        $updated = $criteria->withCapability('chat');

        self::assertSame($criteria, $updated);
    }

    #[Test]
    public function withCapabilityAcceptsModelCapabilityEnum(): void
    {
        $criteria = new ModelSelectionCriteria();

        $updated = $criteria->withCapability(ModelCapability::STREAMING);

        self::assertSame(['streaming'], $updated->capabilities);
    }

    #[Test]
    public function withCapabilityReturnsSameInstanceForDuplicateEnum(): void
    {
        $criteria = new ModelSelectionCriteria(capabilities: ['tools']);

        $updated = $criteria->withCapability(ModelCapability::TOOLS);

        self::assertSame($criteria, $updated);
    }

    #[Test]
    public function withCapabilityPreservesOtherProperties(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['chat'],
            adapterTypes: ['openai'],
            minContextLength: 4096,
            maxCostInput: 200,
            preferLowestCost: true,
        );

        $updated = $criteria->withCapability('vision');

        self::assertSame(['openai'], $updated->adapterTypes);
        self::assertSame(4096, $updated->minContextLength);
        self::assertSame(200, $updated->maxCostInput);
        self::assertTrue($updated->preferLowestCost);
    }

    // ──────────────────────────────────────────────
    // withAdapterType
    // ──────────────────────────────────────────────

    #[Test]
    public function withAdapterTypeAddsNewType(): void
    {
        $criteria = new ModelSelectionCriteria(adapterTypes: ['openai']);

        $updated = $criteria->withAdapterType('anthropic');

        self::assertSame(['openai', 'anthropic'], $updated->adapterTypes);
        self::assertSame(['openai'], $criteria->adapterTypes);
    }

    #[Test]
    public function withAdapterTypeReturnsSameInstanceForDuplicate(): void
    {
        $criteria = new ModelSelectionCriteria(adapterTypes: ['openai']);

        $updated = $criteria->withAdapterType('openai');

        self::assertSame($criteria, $updated);
    }

    #[Test]
    public function withAdapterTypePreservesOtherProperties(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['chat'],
            adapterTypes: [],
            minContextLength: 2048,
            maxCostInput: 100,
            preferLowestCost: false,
        );

        $updated = $criteria->withAdapterType('gemini');

        self::assertSame(['chat'], $updated->capabilities);
        self::assertSame(2048, $updated->minContextLength);
        self::assertSame(100, $updated->maxCostInput);
        self::assertFalse($updated->preferLowestCost);
    }

    // ──────────────────────────────────────────────
    // withMinContextLength
    // ──────────────────────────────────────────────

    #[Test]
    public function withMinContextLengthCreatesNewInstance(): void
    {
        $criteria = new ModelSelectionCriteria(minContextLength: 1024);

        $updated = $criteria->withMinContextLength(8192);

        self::assertSame(8192, $updated->minContextLength);
        self::assertSame(1024, $criteria->minContextLength);
    }

    #[Test]
    public function withMinContextLengthPreservesOtherProperties(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['embeddings'],
            adapterTypes: ['ollama'],
            maxCostInput: 50,
            preferLowestCost: true,
        );

        $updated = $criteria->withMinContextLength(16384);

        self::assertSame(['embeddings'], $updated->capabilities);
        self::assertSame(['ollama'], $updated->adapterTypes);
        self::assertSame(50, $updated->maxCostInput);
        self::assertTrue($updated->preferLowestCost);
    }

    // ──────────────────────────────────────────────
    // withMaxCostInput
    // ──────────────────────────────────────────────

    #[Test]
    public function withMaxCostInputCreatesNewInstance(): void
    {
        $criteria = new ModelSelectionCriteria(maxCostInput: 100);

        $updated = $criteria->withMaxCostInput(500);

        self::assertSame(500, $updated->maxCostInput);
        self::assertSame(100, $criteria->maxCostInput);
    }

    #[Test]
    public function withMaxCostInputPreservesOtherProperties(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['chat', 'tools'],
            adapterTypes: ['mistral'],
            minContextLength: 4096,
            preferLowestCost: false,
        );

        $updated = $criteria->withMaxCostInput(999);

        self::assertSame(['chat', 'tools'], $updated->capabilities);
        self::assertSame(['mistral'], $updated->adapterTypes);
        self::assertSame(4096, $updated->minContextLength);
        self::assertFalse($updated->preferLowestCost);
    }

    // ──────────────────────────────────────────────
    // withLowestCostPreference
    // ──────────────────────────────────────────────

    #[Test]
    public function withLowestCostPreferenceEnablesByDefault(): void
    {
        $criteria = new ModelSelectionCriteria();

        $updated = $criteria->withLowestCostPreference();

        self::assertTrue($updated->preferLowestCost);
        self::assertFalse($criteria->preferLowestCost);
    }

    #[Test]
    public function withLowestCostPreferenceCanDisable(): void
    {
        $criteria = new ModelSelectionCriteria(preferLowestCost: true);

        $updated = $criteria->withLowestCostPreference(false);

        self::assertFalse($updated->preferLowestCost);
        self::assertTrue($criteria->preferLowestCost);
    }

    #[Test]
    public function withLowestCostPreferencePreservesOtherProperties(): void
    {
        $criteria = new ModelSelectionCriteria(
            capabilities: ['audio'],
            adapterTypes: ['openai'],
            minContextLength: 2048,
            maxCostInput: 300,
        );

        $updated = $criteria->withLowestCostPreference(true);

        self::assertSame(['audio'], $updated->capabilities);
        self::assertSame(['openai'], $updated->adapterTypes);
        self::assertSame(2048, $updated->minContextLength);
        self::assertSame(300, $updated->maxCostInput);
    }

    // ──────────────────────────────────────────────
    // Builder chaining
    // ──────────────────────────────────────────────

    #[Test]
    public function builderMethodsCanBeChained(): void
    {
        $criteria = (new ModelSelectionCriteria())
            ->withCapability('chat')
            ->withCapability(ModelCapability::VISION)
            ->withAdapterType('openai')
            ->withAdapterType('anthropic')
            ->withMinContextLength(8192)
            ->withMaxCostInput(500)
            ->withLowestCostPreference();

        self::assertSame(['chat', 'vision'], $criteria->capabilities);
        self::assertSame(['openai', 'anthropic'], $criteria->adapterTypes);
        self::assertSame(8192, $criteria->minContextLength);
        self::assertSame(500, $criteria->maxCostInput);
        self::assertTrue($criteria->preferLowestCost);
        self::assertTrue($criteria->hasCriteria());
    }
}
