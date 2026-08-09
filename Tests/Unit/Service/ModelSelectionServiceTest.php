<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use LogicException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\ModelSelectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Unit tests for ModelSelectionService.
 *
 * Tests the dynamic model selection logic based on criteria.
 */
final class ModelSelectionServiceTest extends TestCase
{
    private ModelRepository&Stub $modelRepository;

    private ModelSelectionService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelRepository = self::createStub(ModelRepository::class);
        $this->subject = new ModelSelectionService($this->modelRepository);
    }

    private function createModel(
        int $uid,
        string $capabilities = 'chat',
        string $adapterType = 'openai',
        int $contextLength = 8000,
        int $costInput = 100,
        int $costOutput = 200,
        int $providerPriority = 50,
        bool $isDefault = false,
        int $sorting = 0,
    ): Model {
        $provider = new Provider();
        $providerReflection = new ReflectionClass($provider);
        $providerUid = $providerReflection->getProperty('uid');
        $providerUid->setValue($provider, $uid);

        $provider->setAdapterType($adapterType);
        $provider->setPriority($providerPriority);

        $model = new Model();
        $reflection = new ReflectionClass($model);
        $uidProperty = $reflection->getProperty('uid');
        $uidProperty->setValue($model, $uid);

        $model->setIdentifier('model-' . $uid);
        $model->setName('Model ' . $uid);
        $model->setCapabilities($capabilities);
        $model->setContextLength($contextLength);
        $model->setCostInput($costInput);
        $model->setCostOutput($costOutput);
        $model->setIsDefault($isDefault);
        $model->setSorting($sorting);
        $model->setProvider($provider);

        return $model;
    }

    private function createConfiguration(string $mode = LlmConfiguration::SELECTION_MODE_FIXED): LlmConfiguration
    {
        $config = new LlmConfiguration();
        $config->setModelSelectionMode($mode);
        return $config;
    }

    /**
     * Create a QueryResultInterface from an array of models.
     *
     * @param array<int, Model> $items
     *
     * @return QueryResultInterface<int, Model>
     */
    private function createQueryResult(array $items): QueryResultInterface
    {
        // @phpstan-ignore return.type (anonymous class implementing interface for test)
        return new class ($items) implements QueryResultInterface {
            /** @var array<int, object> */
            private array $items;

            /**
             * @param array<int, object> $items
             */
            public function __construct(array $items)
            {
                $this->items = array_values($items);
            }

            public function setQuery(QueryInterface $query): void
            {
                // Intentionally empty: this in-memory test double has no backing query to bind.
            }

            public function getFirst(): ?object
            {
                return $this->items[0] ?? null;
            }

            /**
             * @return list<object>
             */
            public function toArray(): array
            {
                // offsetSet()/offsetUnset() can leave gaps, so re-index rather
                // than asserting listness of the stored array.
                return array_values($this->items);
            }

            public function count(): int
            {
                return count($this->items);
            }

            public function getQuery(): QueryInterface
            {
                throw new LogicException('Not implemented', 7771386590);
            }

            public function offsetExists($offset): bool
            {
                if (!is_int($offset)) {
                    return false;
                }

                return isset($this->items[$offset]);
            }

            public function offsetGet($offset): mixed
            {
                if (!is_int($offset)) {
                    return null;
                }

                return $this->items[$offset];
            }

            public function offsetSet($offset, $value): void
            {
                if (is_object($value) && is_int($offset)) {
                    $this->items[$offset] = $value;
                }
            }

            public function offsetUnset($offset): void
            {
                if (is_int($offset)) {
                    unset($this->items[$offset]);
                }
            }

            public function current(): object
            {
                $current = current($this->items);
                assert($current !== false);
                return $current;
            }

            public function next(): void
            {
                next($this->items);
            }

            public function key(): int
            {
                return (int)key($this->items);
            }

            public function valid(): bool
            {
                return key($this->items) !== null;
            }

            public function rewind(): void
            {
                reset($this->items);
            }
        };
    }

    // resolveModel tests

    #[Test]
    public function resolveModelReturnsFixedModelWhenModeIsFixed(): void
    {
        $model = $this->createModel(1);
        $config = $this->createConfiguration(LlmConfiguration::SELECTION_MODE_FIXED);
        $config->setLlmModel($model);

        $result = $this->subject->resolveModel($config, null);

        self::assertSame($model, $result);
    }

    #[Test]
    public function resolveModelReturnsDynamicModelWhenModeIsCriteria(): void
    {
        $model1 = $this->createModel(1, 'chat', 'openai', 8000);
        $model2 = $this->createModel(2, 'chat,vision', 'anthropic', 128000);

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model1, $model2]));

        $config = $this->createConfiguration(LlmConfiguration::SELECTION_MODE_CRITERIA);
        $config->setModelSelectionCriteriaArray(['capabilities' => ['vision']]);

        $result = $this->subject->resolveModel($config, null);

        self::assertSame($model2, $result);
    }

    // findMatchingModel tests

    #[Test]
    public function findMatchingModelReturnsNullWhenNoCandidates(): void
    {
        $model = $this->createModel(1, 'chat');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model]));

        $result = $this->subject->findMatchingModel(['capabilities' => ['vision']]);

        self::assertNull($result);
    }

    #[Test]
    public function findMatchingModelReturnsFirstMatchingModel(): void
    {
        $model1 = $this->createModel(1, 'chat', 'openai', 8000, 100, 200, 50, false, 10);
        $model2 = $this->createModel(2, 'chat', 'openai', 16000, 50, 100, 50, false, 20);

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model1, $model2]));

        $result = $this->subject->findMatchingModel(['capabilities' => ['chat']]);

        // Both match, should return first by sorting
        self::assertSame($model1, $result);
    }

    #[Test]
    public function findMatchingModelPrefersHigherPriorityProvider(): void
    {
        $model1 = $this->createModel(1, 'chat', 'openai', 8000, 100, 200, 30);
        $model2 = $this->createModel(2, 'chat', 'anthropic', 8000, 100, 200, 80);

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model1, $model2]));

        $result = $this->subject->findMatchingModel(['capabilities' => ['chat']]);

        // Model 2 has higher priority provider (80 vs 30)
        self::assertSame($model2, $result);
    }

    #[Test]
    public function findMatchingModelPrefersLowestCostWhenRequested(): void
    {
        $model1 = $this->createModel(1, 'chat', 'openai', 8000, 500, 1000, 50);
        $model2 = $this->createModel(2, 'chat', 'openai', 8000, 100, 200, 50);

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model1, $model2]));

        $result = $this->subject->findMatchingModel([
            'capabilities' => ['chat'],
            'preferLowestCost' => true,
        ]);

        // Model 2 has lower cost (300 vs 1500 total)
        self::assertSame($model2, $result);
    }

    #[Test]
    public function findMatchingModelPrefersDefaultModel(): void
    {
        $model1 = $this->createModel(1, 'chat', 'openai', 8000, 100, 200, 50, false);
        $model2 = $this->createModel(2, 'chat', 'openai', 8000, 100, 200, 50, true);

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model1, $model2]));

        $result = $this->subject->findMatchingModel(['capabilities' => ['chat']]);

        // Model 2 is default
        self::assertSame($model2, $result);
    }

    // modelMatchesCriteria tests

    #[Test]
    public function modelMatchesCriteriaReturnsTrueForEmptyCriteria(): void
    {
        $model = $this->createModel(1, 'chat');

        $result = $this->subject->modelMatchesCriteria($model, []);

        self::assertTrue($result);
    }

    #[Test]
    public function modelMatchesCriteriaChecksCapabilities(): void
    {
        $model = $this->createModel(1, 'chat,vision');

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'capabilities' => ['chat'],
        ]));

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'capabilities' => ['vision'],
        ]));

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'capabilities' => ['chat', 'vision'],
        ]));

        self::assertFalse($this->subject->modelMatchesCriteria($model, [
            'capabilities' => ['tools'],
        ]));
    }

    #[Test]
    public function modelMatchesCriteriaRejectsUnknownCapabilityToken(): void
    {
        // Documents the no-change-for-unknowns contract across the
        // slice 16b migration: legacy `hasCapability()` already used
        // strict `in_array(...,true)` over `explode(',')` so unknown
        // criteria tokens already returned false; the typed path
        // continues to do so via `ModelCapability::tryFrom()`. The
        // assertion stays passing both before and after the migration —
        // pinning that this property is preserved.
        $model = $this->createModel(1, 'chat,vision');

        self::assertFalse($this->subject->modelMatchesCriteria($model, [
            'capabilities' => ['not-a-real-capability'],
        ]));

        // And mixed — a valid capability the model HAS, plus an
        // unknown one, should still fail (every required capability
        // must match).
        self::assertFalse($this->subject->modelMatchesCriteria($model, [
            'capabilities' => ['chat', 'not-a-real-capability'],
        ]));
    }

    #[Test]
    public function modelMatchesCriteriaTrimsCapabilityTokensFromExternalInput(): void
    {
        // External input (configuration form, wizard form) sometimes
        // carries stray whitespace; the typed CapabilitySet trims
        // before `tryFrom()` so `' chat'` resolves the same as `'chat'`.
        $model = $this->createModel(1, 'chat,vision');

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'capabilities' => [' chat'],
        ]));
    }

    #[Test]
    public function modelMatchesCriteriaChecksAdapterTypes(): void
    {
        $model = $this->createModel(1, 'chat', 'openai');

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'adapterTypes' => ['openai'],
        ]));

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'adapterTypes' => ['openai', 'anthropic'],
        ]));

        self::assertFalse($this->subject->modelMatchesCriteria($model, [
            'adapterTypes' => ['anthropic'],
        ]));
    }

    #[Test]
    public function modelMatchesCriteriaChecksMinContextLength(): void
    {
        $model = $this->createModel(1, 'chat', 'openai', 32000);

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'minContextLength' => 16000,
        ]));

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'minContextLength' => 32000,
        ]));

        self::assertFalse($this->subject->modelMatchesCriteria($model, [
            'minContextLength' => 64000,
        ]));
    }

    #[Test]
    public function modelMatchesCriteriaSkipsModelsWithUnknownContextLength(): void
    {
        $model = $this->createModel(1, 'chat', 'openai', 0);

        self::assertFalse($this->subject->modelMatchesCriteria($model, [
            'minContextLength' => 16000,
        ]));
    }

    #[Test]
    public function modelMatchesCriteriaChecksMaxCostInput(): void
    {
        $model = $this->createModel(1, 'chat', 'openai', 8000, 500);

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'maxCostInput' => 1000,
        ]));

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'maxCostInput' => 500,
        ]));

        self::assertFalse($this->subject->modelMatchesCriteria($model, [
            'maxCostInput' => 200,
        ]));
    }

    #[Test]
    public function modelMatchesCriteriaAllowsModelsWithUnknownCost(): void
    {
        $model = $this->createModel(1, 'chat', 'openai', 8000, 0);

        self::assertTrue($this->subject->modelMatchesCriteria($model, [
            'maxCostInput' => 100,
        ]));
    }

    #[Test]
    public function modelMatchesCriteriaRequiresProviderForAdapterTypeCheck(): void
    {
        $model = new Model();
        $reflection = new ReflectionClass($model);
        $uidProperty = $reflection->getProperty('uid');
        $uidProperty->setValue($model, 1);

        $model->setCapabilities('chat');
        // No provider set

        self::assertFalse($this->subject->modelMatchesCriteria($model, [
            'adapterTypes' => ['openai'],
        ]));
    }

    // findCandidates tests

    #[Test]
    public function findCandidatesReturnsAllMatchingModels(): void
    {
        $model1 = $this->createModel(1, 'chat,vision');
        $model2 = $this->createModel(2, 'chat');
        $model3 = $this->createModel(3, 'chat,vision,tools');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model1, $model2, $model3]));

        $result = $this->subject->findCandidates(['capabilities' => ['vision']]);

        self::assertCount(2, $result);
        self::assertContains($model1, $result);
        self::assertContains($model3, $result);
        self::assertNotContains($model2, $result);
    }

    #[Test]
    public function findCandidatesReturnsEmptyArrayWhenNoMatches(): void
    {
        $model = $this->createModel(1, 'chat');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model]));

        $result = $this->subject->findCandidates(['capabilities' => ['embeddings']]);

        self::assertEmpty($result);
    }

    // resolveModel tests

    #[Test]
    public function resolveModelReturnsCriteriaMatchWhenUsingCriteriaMode(): void
    {
        $model = $this->createModel(1, 'chat,vision');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model]));

        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('usesCriteriaSelection')->willReturn(true);
        $configuration->method('getModelSelectionCriteriaArray')->willReturn([
            'capabilities' => ['chat'],
        ]);

        $result = $this->subject->resolveModel($configuration, null);

        self::assertSame($model, $result);
    }

    #[Test]
    public function sortCandidatesHandlesZeroCostAsUnknown(): void
    {
        $model1 = $this->createModel(1, 'chat', 'openai', 8000, 0, 0, 50);  // Unknown cost
        $model2 = $this->createModel(2, 'chat', 'openai', 8000, 100, 200, 50);  // Known cost

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$model1, $model2]));

        $result = $this->subject->findMatchingModel([
            'capabilities' => ['chat'],
            'preferLowestCost' => true,
        ]);

        // Model 2 should be preferred (known cost vs unknown which is treated as highest)
        self::assertSame($model2, $result);
    }

    // getSelectionModes tests

    #[Test]
    public function getSelectionModesReturnsAvailableModes(): void
    {
        $modes = ModelSelectionService::getSelectionModes();

        self::assertArrayHasKey(LlmConfiguration::SELECTION_MODE_FIXED, $modes);
        self::assertArrayHasKey(LlmConfiguration::SELECTION_MODE_CRITERIA, $modes);
        self::assertSame('Fixed Model', $modes[LlmConfiguration::SELECTION_MODE_FIXED]);
        self::assertSame('Dynamic (Criteria)', $modes[LlmConfiguration::SELECTION_MODE_CRITERIA]);
    }

    // ==================== operation capability (ADR-138) ====================

    /**
     * A subject wired to a specific enforcement setting.
     *
     * `$mode` is what the extension configuration returns for
     * `routing.operationCapabilityEnforcement`; null omits the key entirely.
     */
    private function subjectWithEnforcement(?string $mode, ?LoggerInterface $logger = null): ModelSelectionService
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(
            $mode === null ? [] : ['routing' => ['operationCapabilityEnforcement' => $mode]],
        );

        return new ModelSelectionService($this->modelRepository, $extensionConfiguration, $logger);
    }

    private function criteriaConfiguration(): LlmConfiguration
    {
        $config = $this->createConfiguration(LlmConfiguration::SELECTION_MODE_CRITERIA);
        $config->setIdentifier('criteria-config');

        return $config;
    }

    #[Test]
    public function criteriaModeRefusesAModelThatCannotDoTheRunningOperation(): void
    {
        // The reported defect: criteria that never mention tools happily
        // resolved a chat-only model, and the failure surfaced as a provider
        // error mid-call.
        $chatOnly = $this->createModel(1, 'chat');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$chatOnly]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray(['capabilities' => ['chat']]);

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('criteria-config');

        $this->subjectWithEnforcement('enforce')->resolveModel($config, ProviderOperation::Tools);
    }

    #[Test]
    public function criteriaModePrefersTheModelThatCanDoTheRunningOperation(): void
    {
        // Both satisfy the stored criteria; only one can do tools. Without the
        // operation the chat-only model wins on sorting order.
        $chatOnly  = $this->createModel(1, 'chat', 'openai', 8000, 100, 200, 50, true, 1);
        $withTools = $this->createModel(2, 'chat,tools', 'openai', 8000, 100, 200, 50, false, 2);

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$chatOnly, $withTools]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray(['capabilities' => ['chat']]);

        $subject = $this->subjectWithEnforcement('enforce');

        self::assertSame($chatOnly, $subject->resolveModel($config, ProviderOperation::Chat));
        self::assertSame($withTools, $subject->resolveModel($config, ProviderOperation::Tools));
    }

    #[Test]
    public function theOperationCapabilityDoesNotOverwriteTheStoredCriteria(): void
    {
        // The stored criteria must survive the merge intact — a wide-context
        // Anthropic model is still required, the operation only adds to that.
        $wrongAdapter = $this->createModel(1, 'chat,tools', 'openai', 200000);
        $tooSmall     = $this->createModel(2, 'chat,tools', 'anthropic', 4000);
        $wanted       = $this->createModel(3, 'chat,tools', 'anthropic', 200000);

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$wrongAdapter, $tooSmall, $wanted]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray([
            'capabilities'     => ['chat'],
            'adapterTypes'     => ['anthropic'],
            'minContextLength' => 100000,
        ]);

        self::assertSame(
            $wanted,
            $this->subjectWithEnforcement('enforce')->resolveModel($config, ProviderOperation::Tools),
        );
    }

    #[Test]
    public function nullOperationLeavesTheSelectionUntouched(): void
    {
        $chatOnly = $this->createModel(1, 'chat');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$chatOnly]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray(['capabilities' => ['chat']]);

        self::assertSame(
            $chatOnly,
            $this->subjectWithEnforcement('enforce')->resolveModel($config, null),
        );
    }

    #[Test]
    public function anOperationThatRequiresNoCapabilityLeavesTheSelectionUntouched(): void
    {
        // Completion is deliberately unmapped (no discoverer writes the
        // capability), so a chat-only model stays eligible.
        $chatOnly = $this->createModel(1, 'chat');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$chatOnly]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray(['capabilities' => ['chat']]);

        self::assertSame(
            $chatOnly,
            $this->subjectWithEnforcement('enforce')->resolveModel($config, ProviderOperation::Completion),
        );
    }

    #[Test]
    public function fixedModeIsNotConstrainedByTheOperation(): void
    {
        // The operator named this model. Nothing is being chosen, so there is
        // nothing to constrain — it still fails at the adapter, as before.
        $chatOnly = $this->createModel(1, 'chat');
        $config   = $this->createConfiguration(LlmConfiguration::SELECTION_MODE_FIXED);
        $config->setLlmModel($chatOnly);

        self::assertSame(
            $chatOnly,
            $this->subjectWithEnforcement('enforce')->resolveModel($config, ProviderOperation::Tools),
        );
    }

    #[Test]
    public function criteriaMatchingNothingAtAllStillReturnsNull(): void
    {
        // Pre-existing "has no model assigned" condition: the criteria match
        // nothing, with or without the operation. That must stay a null return
        // rather than become an UnsupportedFeatureException.
        $chatOnly = $this->createModel(1, 'chat', 'openai');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$chatOnly]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray(['adapterTypes' => ['anthropic']]);

        self::assertNull(
            $this->subjectWithEnforcement('enforce')->resolveModel($config, ProviderOperation::Tools),
        );
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function enforcementSettingProvider(): iterable
    {
        yield 'enforce'        => ['enforce'];
        yield 'observe'        => ['observe'];
        yield 'missing key'    => [null];
        yield 'typo enforces'  => ['observ'];
    }

    #[Test]
    #[DataProvider('enforcementSettingProvider')]
    public function anEmptyCapabilityCsvIsUndeclaredAndSkipsTheCheck(?string $enforcement): void
    {
        // The decision that keeps this shippable: an empty capability field is
        // an absent statement, not "cannot". Refusing every model that never
        // filled the optional field would break working installations, so the
        // check is skipped in BOTH switch positions.
        $undeclared = $this->createModel(1, '');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$undeclared]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray([]);

        self::assertSame(
            $undeclared,
            $this->subjectWithEnforcement($enforcement)->resolveModel($config, ProviderOperation::Tools),
        );
    }

    #[Test]
    public function observeModeKeepsTheModelAndReportsTheMismatch(): void
    {
        $chatOnly = $this->createModel(1, 'chat');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$chatOnly]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray(['capabilities' => ['chat']]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        self::assertSame(
            $chatOnly,
            $this->subjectWithEnforcement('observe', $logger)->resolveModel($config, ProviderOperation::Tools),
        );
    }

    #[Test]
    public function observeModeIsSilentForAnUndeclaredModel(): void
    {
        // An empty capability set is not a mismatch, it is an absent statement.
        // Reporting it on every call would bury the real findings.
        $undeclared = $this->createModel(1, '');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$undeclared]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->subjectWithEnforcement('observe', $logger)->resolveModel($config, ProviderOperation::Tools);
    }

    #[Test]
    public function anUnreadableExtensionConfigurationEnforces(): void
    {
        // Fail-closed like the tool gate (ADR-113): a broken setting must not
        // silently disable the axis.
        $chatOnly = $this->createModel(1, 'chat');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$chatOnly]));

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new ExtensionConfigurationExtensionNotConfiguredException());

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray(['capabilities' => ['chat']]);

        $this->expectException(UnsupportedFeatureException::class);

        (new ModelSelectionService($this->modelRepository, $extensionConfiguration))
            ->resolveModel($config, ProviderOperation::Tools);
    }

    #[Test]
    public function noExtensionConfigurationAtAllEnforces(): void
    {
        $chatOnly = $this->createModel(1, 'chat');

        $this->modelRepository
            ->method('findActive')
            ->willReturn($this->createQueryResult([$chatOnly]));

        $config = $this->criteriaConfiguration();
        $config->setModelSelectionCriteriaArray(['capabilities' => ['chat']]);

        $this->expectException(UnsupportedFeatureException::class);

        $this->subject->resolveModel($config, ProviderOperation::Tools);
    }
}
