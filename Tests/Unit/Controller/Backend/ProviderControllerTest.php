<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Unit tests for ProviderController AJAX actions.
 *
 * Tests the PSR-7 AJAX handlers directly without Extbase initialization.
 * Uses reflection to create controller with only the required dependencies.
 */
#[AllowMockObjectsWithoutExpectations]
final class ProviderControllerTest extends TestCase
{
    private ProviderRepository&MockObject $providerRepository;
    private PersistenceManagerInterface&MockObject $persistenceManager;
    private ProviderAdapterRegistry&MockObject $providerAdapterRegistry;
    private ProviderController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerRepository = $this->createMock(ProviderRepository::class);
        $this->providerAdapterRegistry = $this->createMock(ProviderAdapterRegistry::class);
        $this->persistenceManager = $this->createMock(PersistenceManagerInterface::class);

        // Create controller using reflection to inject only required dependencies
        $this->subject = $this->createControllerWithDependencies();
    }

    /**
     * Create controller instance with only the dependencies needed for AJAX actions.
     * Uses reflection to bypass constructor and set only required properties.
     */
    private function createControllerWithDependencies(): ProviderController
    {
        $reflection = new ReflectionClass(ProviderController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        // Set only the properties needed for AJAX actions
        $this->setPrivateProperty($controller, 'providerRepository', $this->providerRepository);
        $this->setPrivateProperty($controller, 'providerAdapterRegistry', $this->providerAdapterRegistry);
        $this->setPrivateProperty($controller, 'persistenceManager', $this->persistenceManager);

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

    /**
     * Decode JSON response and assert it's an array.
     *
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function createProvider(int $uid, bool $isActive): Provider
    {
        $provider = new Provider();
        $reflection = new ReflectionClass($provider);
        $uidProperty = $reflection->getProperty('uid');
        $uidProperty->setValue($provider, $uid);
        $provider->setIsActive($isActive);
        return $provider;
    }

    #[Test]
    public function toggleActiveActionActivatesInactiveProvider(): void
    {
        $provider = $this->createProvider(1, false);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $this->providerRepository
            ->expects(self::once())
            ->method('update')
            ->with($provider);

        $this->persistenceManager
            ->expects(self::once())
            ->method('persistAll');

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertTrue($data['isActive']);
        self::assertTrue($provider->isActive());
    }

    #[Test]
    public function toggleActiveActionDeactivatesActiveProvider(): void
    {
        $provider = $this->createProvider(1, true);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $this->providerRepository
            ->expects(self::once())
            ->method('update')
            ->with($provider);

        $this->persistenceManager
            ->expects(self::once())
            ->method('persistAll');

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertFalse($data['isActive']);
        self::assertFalse($provider->isActive());
    }

    #[Test]
    public function toggleActiveActionReturnsErrorForMissingUid(): void
    {
        $this->providerRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No provider UID', $data['error']);
    }

    #[Test]
    public function toggleActiveActionReturnsErrorForNonexistentProvider(): void
    {
        $this->providerRepository
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
        $provider = $this->createProvider(1, true);

        $this->providerRepository
            ->method('findByUid')
            ->willReturn($provider);

        $this->providerRepository
            ->method('update')
            ->willThrowException(new RuntimeException('Database error'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Database error', $data['error']);
    }

    #[Test]
    public function testConnectionActionReturnsSuccessForValidProvider(): void
    {
        $provider = $this->createProvider(1, true);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $this->providerAdapterRegistry
            ->expects(self::once())
            ->method('testProviderConnection')
            ->with($provider)
            ->willReturn([
                'success' => true,
                'message' => 'Connection successful',
                'models' => ['gpt-4', 'gpt-3.5-turbo'],
            ]);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testConnectionAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertEquals('Connection successful', $data['message']);
        self::assertIsArray($data['models']);
        self::assertCount(2, $data['models']);
    }

    #[Test]
    public function testConnectionActionReturnsErrorForMissingUid(): void
    {
        $this->providerRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->testConnectionAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function testConnectionActionReturnsErrorForNonexistentProvider(): void
    {
        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['uid' => 99999]);
        $response = $this->subject->testConnectionAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function testConnectionActionReturnsErrorOnException(): void
    {
        $provider = $this->createProvider(1, true);

        $this->providerRepository
            ->method('findByUid')
            ->willReturn($provider);

        $this->providerAdapterRegistry
            ->method('testProviderConnection')
            ->willThrowException(new RuntimeException('Connection failed'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testConnectionAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($data['success']);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Connection failed', $data['error']);
    }

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
    public function toggleActiveActionWithNonNumericUidReturnsError(): void
    {
        $request = $this->createRequest(['uid' => 'not-a-number']);

        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function testConnectionActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->testConnectionAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function testConnectionActionReturnsFailureResult(): void
    {
        $provider = $this->createProvider(1, true);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $this->providerAdapterRegistry
            ->expects(self::once())
            ->method('testProviderConnection')
            ->with($provider)
            ->willReturn([
                'success' => false,
                'message' => 'Invalid API key',
                'models' => [],
            ]);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testConnectionAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($data['success']);
        self::assertEquals('Invalid API key', $data['message']);
    }
}
