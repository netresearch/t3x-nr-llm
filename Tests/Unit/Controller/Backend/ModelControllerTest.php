<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use LogicException;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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

    private ProviderRepository&Stub $providerRepository;

    private PersistenceManagerInterface&MockObject $persistenceManager;

    private ModelController $subject;

    private mixed $previousBeUser;

    protected function setUp(): void
    {
        parent::setUp();

        // The AJAX actions are guarded by RequiresBackendAdminTrait (ADR-037);
        // provide an admin backend user so these tests reach the action body.
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->providerRepository = self::createStub(ProviderRepository::class);
        $this->persistenceManager = $this->createMock(PersistenceManagerInterface::class);

        // Create controller using reflection to inject only required dependencies
        $this->subject = $this->createControllerWithDependencies();
    }

    protected function tearDown(): void
    {
        if ($this->previousBeUser === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->previousBeUser;
        }

        parent::tearDown();
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
        // REC #8b: typed catches log via LoggerInterface — NullLogger
        // for unit tests so the property is initialised.
        $this->setPrivateProperty($controller, 'logger', new NullLogger());

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    /**
     * @param array<string, mixed> $body
     */
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

    /**
     * Decode JSON response body and return typed array.
     *
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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

        $data = $this->decodeJsonResponse($response);

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

        $data = $this->decodeJsonResponse($response);

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

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
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

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
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
            ->willThrowException(new LogicException('Database error'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('See system log', $data['error']);
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

        $data = $this->decodeJsonResponse($response);

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

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
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

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
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
            ->willThrowException(new LogicException('Database error'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->setDefaultAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('See system log', $data['error']);
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

            public function setQuery(QueryInterface $query): void
            {
                // Intentionally empty: this in-memory stub ignores the query object.
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
                throw new LogicException('Not implemented', 7771386589);
            }

            public function offsetExists($offset): bool
            {
                return is_int($offset) && isset($this->items[$offset]);
            }

            public function offsetGet($offset): mixed
            {
                return is_int($offset) ? ($this->items[$offset] ?? null) : null;
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

        $this->modelRepository
            ->expects(self::once())
            ->method('findByProviderUid')
            ->with(1)
            ->willReturn($queryResult);

        $request = $this->createRequest(['providerUid' => 1]);
        $response = $this->subject->getByProviderAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertIsArray($data['models']);
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

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No provider UID', $data['error']);
    }

    #[Test]
    public function getByProviderActionReturnsErrorOnException(): void
    {
        $this->modelRepository
            ->method('findByProviderUid')
            ->willThrowException(new LogicException('Database error'));

        $request = $this->createRequest(['providerUid' => 1]);
        $response = $this->subject->getByProviderAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('See system log', $data['error']);
    }

    // Edge case tests for non-array body

    #[Test]
    public function toggleActiveActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function setDefaultActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->setDefaultAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function getByProviderActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->getByProviderAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }
}
