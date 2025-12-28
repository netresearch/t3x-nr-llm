<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Unit tests for ModelController AJAX actions.
 *
 * Tests the PSR-7 AJAX handlers directly without Extbase initialization.
 * Uses reflection to create controller with only the required dependencies.
 */
#[AllowMockObjectsWithoutExpectations]
final class ModelControllerTest extends TestCase
{
    private ModelRepository&MockObject $modelRepository;
    private ProviderRepository&MockObject $providerRepository;
    private PersistenceManagerInterface&MockObject $persistenceManager;
    private ProviderAdapterRegistry&MockObject $providerAdapterRegistry;
    private ModelController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->providerRepository = $this->createMock(ProviderRepository::class);
        $this->persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $this->providerAdapterRegistry = $this->createMock(ProviderAdapterRegistry::class);

        // Create controller using reflection to inject only required dependencies
        $this->subject = $this->createControllerWithDependencies();
    }

    /**
     * Create controller instance with only the dependencies needed for AJAX actions.
     * Uses reflection to bypass constructor and set only required properties.
     */
    private function createControllerWithDependencies(): ModelController
    {
        $reflection = new ReflectionClass(ModelController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        // Set only the properties needed for AJAX actions
        $this->setPrivateProperty($controller, 'modelRepository', $this->modelRepository);
        $this->setPrivateProperty($controller, 'providerRepository', $this->providerRepository);
        $this->setPrivateProperty($controller, 'persistenceManager', $this->persistenceManager);
        $this->setPrivateProperty($controller, 'providerAdapterRegistry', $this->providerAdapterRegistry);

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    private function createRequest(array $body): ServerRequest
    {
        // TYPO3's ServerRequest has ($uri, $method) signature, not ($method, $uri)
        return (new ServerRequest('/ajax/test', 'POST'))
            ->withParsedBody($body);
    }

    private function createModel(int $uid, bool $isActive, bool $isDefault = false): Model
    {
        $model = new Model();
        $reflection = new ReflectionClass($model);
        $uidProperty = $reflection->getProperty('uid');
        $uidProperty->setValue($model, $uid);
        $model->setIsActive($isActive);
        $model->setIsDefault($isDefault);
        return $model;
    }

    // toggleActiveAction tests

    #[Test]
    public function toggleActiveActionActivatesInactiveModel(): void
    {
        $model = $this->createModel(1, false);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $this->modelRepository
            ->expects(self::once())
            ->method('update')
            ->with($model);

        $this->persistenceManager
            ->expects(self::once())
            ->method('persistAll');

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertTrue($data['isActive']);
        self::assertTrue($model->isActive());
    }

    #[Test]
    public function toggleActiveActionDeactivatesActiveModel(): void
    {
        $model = $this->createModel(1, true);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $this->modelRepository
            ->expects(self::once())
            ->method('update')
            ->with($model);

        $this->persistenceManager
            ->expects(self::once())
            ->method('persistAll');

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertFalse($data['isActive']);
        self::assertFalse($model->isActive());
    }

    #[Test]
    public function toggleActiveActionReturnsErrorForMissingUid(): void
    {
        $this->modelRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->toggleActiveAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('No model UID', $data['error']);
    }

    #[Test]
    public function toggleActiveActionReturnsErrorForNonexistentModel(): void
    {
        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['uid' => 99999]);
        $response = $this->subject->toggleActiveAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function toggleActiveActionReturnsErrorOnException(): void
    {
        $model = $this->createModel(1, true);

        $this->modelRepository
            ->method('findByUid')
            ->willReturn($model);

        $this->modelRepository
            ->method('update')
            ->willThrowException(new RuntimeException('Database error'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('Database error', $data['error']);
    }

    // setDefaultAction tests

    #[Test]
    public function setDefaultActionSetsModelAsDefault(): void
    {
        $model = $this->createModel(1, true, false);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $this->modelRepository
            ->expects(self::once())
            ->method('setAsDefault')
            ->with($model);

        $this->persistenceManager
            ->expects(self::once())
            ->method('persistAll');

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->setDefaultAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
    }

    #[Test]
    public function setDefaultActionReturnsErrorForMissingUid(): void
    {
        $this->modelRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->setDefaultAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('No model UID', $data['error']);
    }

    #[Test]
    public function setDefaultActionReturnsErrorForNonexistentModel(): void
    {
        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['uid' => 99999]);
        $response = $this->subject->setDefaultAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function setDefaultActionReturnsErrorOnException(): void
    {
        $model = $this->createModel(1, true);

        $this->modelRepository
            ->method('findByUid')
            ->willReturn($model);

        $this->modelRepository
            ->method('setAsDefault')
            ->willThrowException(new RuntimeException('Database error'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->setDefaultAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('Database error', $data['error']);
    }

    // getByProviderAction tests

    #[Test]
    public function getByProviderActionReturnsModelsForProvider(): void
    {
        $model1 = $this->createModel(1, true);
        $model1->setIdentifier('gpt-4');
        $model1->setName('GPT-4');

        $model2 = $this->createModel(2, true);
        $model2->setIdentifier('gpt-3.5');
        $model2->setName('GPT-3.5');

        // Create a mock that can be iterated and satisfies return type
        /** @phpstan-ignore-next-line Anonymous class implementing QueryResultInterface for test */
        $queryResult = new class ([$model1, $model2]) implements QueryResultInterface {
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
                throw new RuntimeException('Not implemented', 7771386589);
            }
            public function offsetExists($offset): bool
            {
                return isset($this->items[$offset]);
            }
            public function offsetGet($offset): mixed
            {
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

        $this->modelRepository
            ->expects(self::once())
            ->method('findByProviderUid')
            ->with(1)
            ->willReturn($queryResult);

        $request = $this->createRequest(['providerUid' => 1]);
        $response = $this->subject->getByProviderAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertCount(2, $data['models']);
    }

    #[Test]
    public function getByProviderActionReturnsErrorForMissingProviderUid(): void
    {
        $this->modelRepository
            ->expects(self::never())
            ->method('findByProviderUid');

        $request = $this->createRequest([]);
        $response = $this->subject->getByProviderAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('No provider UID', $data['error']);
    }

    #[Test]
    public function getByProviderActionReturnsErrorOnException(): void
    {
        $this->modelRepository
            ->method('findByProviderUid')
            ->willThrowException(new RuntimeException('Database error'));

        $request = $this->createRequest(['providerUid' => 1]);
        $response = $this->subject->getByProviderAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('Database error', $data['error']);
    }

    // testModelAction tests

    #[Test]
    public function testModelActionReturnsErrorForMissingUid(): void
    {
        $this->modelRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->testModelAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('No model UID', $data['error']);
    }

    #[Test]
    public function testModelActionReturnsErrorForNonexistentModel(): void
    {
        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['uid' => 99999]);
        $response = $this->subject->testModelAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function testModelActionReturnsErrorForModelWithoutProvider(): void
    {
        $model = $this->createModel(1, true);
        $model->setProviderUid(0);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testModelAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('no provider', $data['error']);
    }

    #[Test]
    public function testModelActionReturnsSuccessOnSuccessfulTest(): void
    {
        $model = $this->createModel(1, true);
        $model->setName('GPT-4');
        $model->setModelId('gpt-4');
        $model->setProviderUid(1);

        $provider = new Provider();
        $providerReflection = new ReflectionClass($provider);
        $providerUidProp = $providerReflection->getProperty('uid');
        $providerUidProp->setValue($provider, 1);
        $provider->setName('OpenAI');
        $provider->setAdapterType('openai');
        $model->setProvider($provider);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $adapter = $this->createMock(ProviderInterface::class);
        $usage = new UsageStatistics(10, 5, 15);
        $completionResponse = new CompletionResponse(
            content: 'OK',
            model: 'gpt-4',
            usage: $usage,
        );

        $adapter
            ->expects(self::once())
            ->method('complete')
            ->willReturn($completionResponse);

        $this->providerAdapterRegistry
            ->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($model)
            ->willReturn($adapter);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testModelAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertStringContainsString('GPT-4', $data['message']);
        self::assertStringContainsString('OK', $data['message']);
    }

    #[Test]
    public function testModelActionReturnsErrorOnAdapterException(): void
    {
        $model = $this->createModel(1, true);
        $model->setName('GPT-4');
        $model->setModelId('gpt-4');
        $model->setProviderUid(1);

        $provider = new Provider();
        $providerReflection = new ReflectionClass($provider);
        $providerUidProp = $providerReflection->getProperty('uid');
        $providerUidProp->setValue($provider, 1);
        $provider->setName('OpenAI');
        $provider->setAdapterType('openai');
        $model->setProvider($provider);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $adapter = $this->createMock(ProviderInterface::class);
        $adapter
            ->method('complete')
            ->willThrowException(new RuntimeException('API connection failed'));

        $this->providerAdapterRegistry
            ->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($model)
            ->willReturn($adapter);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testModelAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($data['success']);
        self::assertStringContainsString('API connection failed', $data['message']);
    }
}
