<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\TestPromptResolverInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Functional tests for LlmModuleController (Dashboard).
 *
 * Tests user pathways:
 * - Pathway 6.2: Quick Test Completion (executeTestAction)
 *
 * Note: Pathway 6.1 (View Dashboard) requires full Extbase/ModuleTemplate
 * infrastructure which is tested at the integration level.
 *
 * Uses reflection to create controller with only AJAX-required dependencies,
 * bypassing Extbase ActionController initialization that requires request context.
 */
#[CoversClass(LlmModuleController::class)]
final class LlmModuleControllerTest extends AbstractFunctionalTestCase
{
    private LlmModuleController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');

        // executeTestAction now requires an authenticated admin
        // (RequiresBackendAdminTrait — ADR-037); set one up so these tests
        // exercise the success paths.
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)

        // Get real services from container
        $llmServiceManager = $this->get(LlmServiceManager::class);
        self::assertInstanceOf(LlmServiceManager::class, $llmServiceManager);

        // Create controller via reflection to inject only the dependencies
        // executeTestAction needs (it does not touch the repositories).
        $this->controller = $this->createControllerWithDependencies($llmServiceManager);
    }

    /**
     * Create the actual AJAX controller with its declared dependencies.
     */
    private function createControllerWithDependencies(
        LlmServiceManagerInterface $llmServiceManager,
        ?TestPromptResolverInterface $testPromptResolver = null,
    ): LlmModuleController {
        $reflection = new ReflectionClass(LlmModuleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $this->setPrivateProperty(
            $controller,
            'llmServiceManager',
            $llmServiceManager,
        );
        $testPromptResolver ??= $this->get(TestPromptResolverInterface::class);
        self::assertInstanceOf(
            TestPromptResolverInterface::class,
            $testPromptResolver,
        );
        $this->setPrivateProperty(
            $controller,
            'testPromptResolver',
            $testPromptResolver,
        );
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
     * Create an Extbase request for actions that need $this->request.
     *
     * @param array<string, mixed> $parsedBody
     */
    private function createExtbaseRequest(array $parsedBody = []): ExtbaseRequest
    {
        $serverRequest = new Typo3ServerRequest();
        $serverRequest = $serverRequest->withParsedBody($parsedBody);

        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('LlmModule');
        $extbaseParameters->setControllerActionName('executeTest');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = $serverRequest->withAttribute('extbase', $extbaseParameters);

        return new ExtbaseRequest($serverRequest);
    }

    // -------------------------------------------------------------------------
    // Pathway 6.2: Quick Test Completion (executeTestAction)
    // -------------------------------------------------------------------------

    #[Test]
    public function executeTestReturnsErrorForMissingProvider(): void
    {
        // Create request with empty provider
        $extbaseRequest = $this->createExtbaseRequest(['prompt' => 'Hello']);

        // Inject the request into the controller
        $this->setPrivateProperty($this->controller, 'request', $extbaseRequest);

        // Act
        $response = $this->controller->executeTestAction();

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsString($body['error']);
        self::assertStringContainsString('No provider specified', $body['error']);
    }

    #[Test]
    public function executeTestReturnsErrorForEmptyProvider(): void
    {
        // Create request with empty provider string
        $extbaseRequest = $this->createExtbaseRequest([
            'provider' => '',
            'prompt' => 'Hello',
        ]);

        // Inject the request into the controller
        $this->setPrivateProperty($this->controller, 'request', $extbaseRequest);

        // Act
        $response = $this->controller->executeTestAction();

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsString($body['error']);
        self::assertStringContainsString('No provider specified', $body['error']);
    }

    #[Test]
    public function executeTestHandlesValidProviderRequest(): void
    {
        $completion = new CompletionResponse(
            content: '  Grüße <script>test</script> "quoted"  ',
            model: 'served-model-snapshot',
            usage: new UsageStatistics(7, 11, 18),
        );
        $controller = $this->createSuccessfulTestController(
            'Caller-supplied prompt',
            'configured-provider-b',
            $completion,
        );
        $request = $this->createExtbaseRequest(
            [
                'provider' => 'configured-provider-b',
                'prompt' => 'Caller-supplied prompt',
            ],
        );
        $this->setPrivateProperty($controller, 'request', $request);

        $response = $controller->executeTestAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame($completion->content, $body['content']);
        self::assertSame('served-model-snapshot', $body['model']);
    }

    #[Test]
    public function executeTestUsesDefaultPromptWhenNotProvided(): void
    {
        $completion = new CompletionResponse(
            content: 'Default prompt response',
            model: 'served-model-snapshot',
            usage: new UsageStatistics(3, 5, 8),
        );
        $controller = $this->createSuccessfulTestController(
            'Default audit prompt',
            'configured-provider-default',
            $completion,
        );
        $request = $this->createExtbaseRequest(
            ['provider' => 'configured-provider-default'],
        );
        $this->setPrivateProperty($controller, 'request', $request);

        $response = $controller->executeTestAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame('Default prompt response', $body['content']);
    }

    #[Test]
    public function executeTestReturnsUsageStatisticsOnSuccess(): void
    {
        $completion = new CompletionResponse(
            content: 'Usage response',
            model: 'served-model-snapshot',
            usage: new UsageStatistics(17, 31, 48),
        );
        $controller = $this->createSuccessfulTestController(
            'Usage audit prompt',
            'configured-provider-usage',
            $completion,
        );
        $request = $this->createExtbaseRequest(
            [
                'provider' => 'configured-provider-usage',
                'prompt' => 'Usage audit prompt',
            ],
        );
        $this->setPrivateProperty($controller, 'request', $request);

        $response = $controller->executeTestAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame(
            [
                'promptTokens' => 17,
                'completionTokens' => 31,
                'totalTokens' => 48,
            ],
            $body['usage'],
        );
    }

    private function createSuccessfulTestController(
        string $expectedPrompt,
        string $expectedProvider,
        CompletionResponse $completion,
    ): LlmModuleController {
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager
            ->expects(self::once())
            ->method('complete')
            ->with(
                $expectedPrompt,
                self::callback(
                    static fn(
                        ?ChatOptions $options,
                    ): bool => $options?->getProvider() === $expectedProvider,
                ),
            )
            ->willReturn($completion);
        $resolver = self::createStub(TestPromptResolverInterface::class);
        $resolver->method('resolve')->willReturn('Default audit prompt');

        return $this->createControllerWithDependencies($manager, $resolver);
    }
}
