<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Base class for Backend E2E tests.
 *
 * Provides utilities for simulating complete user pathways through
 * backend modules, including request creation and response validation.
 */
abstract class AbstractBackendE2ETestCase extends AbstractFunctionalTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Import standard fixtures for all E2E tests
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');
    }

    /**
     * Create a PSR-7 POST request with JSON body.
     *
     * @param array<string, mixed> $data
     */
    protected function createJsonRequest(string $uri, array $data): ServerRequestInterface
    {
        $request = new ServerRequest('POST', $uri);
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody(Utils::streamFor(json_encode($data, JSON_THROW_ON_ERROR)));

        return $request;
    }

    /**
     * Create a PSR-7 POST request with form data.
     *
     * @param array<string, mixed> $data
     */
    protected function createFormRequest(string $uri, array $data): ServerRequestInterface
    {
        $request = new ServerRequest('POST', $uri);
        $request = $request->withParsedBody($data);

        return $request;
    }

    /**
     * Create an Extbase request for controller actions.
     *
     * @param array<string, mixed> $parsedBody
     */
    protected function createExtbaseRequest(
        string $controllerName,
        string $actionName,
        array $parsedBody = [],
    ): ExtbaseRequest {
        $serverRequest = new Typo3ServerRequest();
        $serverRequest = $serverRequest->withParsedBody($parsedBody);

        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName($controllerName);
        $extbaseParameters->setControllerActionName($actionName);
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = $serverRequest->withAttribute('extbase', $extbaseParameters);

        return new ExtbaseRequest($serverRequest);
    }

    /**
     * Set a private property on an object using reflection.
     */
    protected function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    /**
     * Assert that a JSON response is successful.
     *
     * @return array<string, mixed> The decoded response body
     */
    protected function assertSuccessResponse(ResponseInterface $response, int $expectedStatus = 200): array
    {
        self::assertSame($expectedStatus, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success'] ?? false, 'Response should indicate success');

        return $body;
    }

    /**
     * Assert that a JSON response indicates an error.
     *
     * @return array<string, mixed> The decoded response body
     */
    protected function assertErrorResponse(
        ResponseInterface $response,
        int $expectedStatus = 400,
        ?string $expectedError = null,
    ): array {
        self::assertSame($expectedStatus, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success'] ?? true, 'Response should indicate failure');
        self::assertArrayHasKey('error', $body);

        if ($expectedError !== null) {
            self::assertSame($expectedError, $body['error']);
        }

        return $body;
    }

    /**
     * Create a controller instance using reflection to bypass Extbase initialization.
     *
     * @template T of object
     *
     * @param class-string<T>      $controllerClass
     * @param array<string, mixed> $dependencies    Property name => value
     *
     * @return T
     */
    protected function createControllerWithReflection(string $controllerClass, array $dependencies): object
    {
        $reflection = new ReflectionClass($controllerClass);
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach ($dependencies as $property => $value) {
            $this->setPrivateProperty($controller, $property, $value);
        }

        return $controller;
    }
}
