<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Service\ModelSelectionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
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
     * @return QueryResultInterface<int, Model>
     */
    private function createQueryResult(array $items): QueryResultInterface
    {
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
            public function setQuery(QueryInterface $query): void {}
            public function getFirst(): ?object
            {
                return $this->items[0] ?? null;
            }
            /**
             * @return list<object>
             */
            public function toArray(): array
            {
                /** @var list<object> */
                return $this->items;
            }
            public function count(): int
            {
                return count($this->items);
            }
            public function getQuery(): QueryInterface
            {
                throw new RuntimeException('Not implemented', 7771386590);
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
                if (!is_int($offset)) {
                    return;
                }
                unset($this->items[$offset]);
            }
            public function current(): mixed
            {
                return current($this->items);
            }
            public function next(): void
            {
                next($this->items);
            }
            public function key(): mixed
            {
                return key($this->items);
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

        $result = $this->subject->resolveModel($config);

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

        $result = $this->subject->resolveModel($config);

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
}
