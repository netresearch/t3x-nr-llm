<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest as GuzzleServerRequest;
use Netresearch\NrLlm\Controller\Backend\ToolPlaygroundController;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\ScriptedToolAdapter;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Functional tests for the admin-only Tool Playground backend module.
 *
 * Renders listAction() through the real ModuleTemplate stack (the playground
 * shell) and asserts the config picker plus the registered tools surface in
 * the markup. The AJAX runAction is covered by the admin-guard and happy-path
 * trace tests below (the agent loop driven through a scripted provider double).
 */
#[CoversClass(ToolPlaygroundController::class)]
final class ToolPlaygroundControllerTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function listActionRendersPlaygroundShell(): void
    {
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('BeUsers.csv');
        $backendUser = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        $toolRegistry = new ToolRegistry([new FakeTool('fetch_logs')]);
        $availability = $this->availabilityFor($toolRegistry);

        $skillRepository = $this->get(SkillRepository::class);
        self::assertInstanceOf(SkillRepository::class, $skillRepository);
        $promptSnippetRepository = $this->get(PromptSnippetRepository::class);
        self::assertInstanceOf(PromptSnippetRepository::class, $promptSnippetRepository);

        $controller = new ToolPlaygroundController(
            $moduleTemplateFactory,
            $configurationRepository,
            $pageRenderer,
            new ToolLoopService(self::createStub(LlmServiceManagerInterface::class), new ToolRegistry([]), $availability),
            $availability,
            $this->get(AllowedToolsResolver::class),
            $skillRepository,
            $promptSnippetRepository,
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $response = $controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        // Config picker over the persisted configurations.
        self::assertStringContainsString('id="nrllm-tool-config"', $body);
        self::assertStringContainsString('Default Configuration', $body);
        // Prompt box, run button and output pane.
        self::assertStringContainsString('id="nrllm-tool-prompt"', $body);
        self::assertStringContainsString('id="nrllm-tool-run"', $body);
        self::assertStringContainsString('id="nrllm-tool-output"', $body);
        // The per-run tool selection lists the registered tool by name (the
        // enable/disable management list — with descriptions — now lives in the
        // separate Tools module, {@see ToolControllerTest}).
        self::assertStringContainsString('fetch_logs', $body);
        self::assertStringContainsString('js-tool-select', $body);
    }

    #[Test]
    public function runActionDeniesNonAdmin(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2); // uid 2 is a non-admin editor (admin=0)

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);

        $emptyRegistry = new ToolRegistry([]);
        $controller    = $this->makeController(
            $configurationRepository,
            $emptyRegistry,
            new ToolLoopService(self::createStub(LlmServiceManagerInterface::class), $emptyRegistry, $this->availabilityFor($emptyRegistry)),
        );

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/run'))
            ->withParsedBody(['configuration' => 1, 'prompt' => 'hi']);
        $response = $controller->runAction($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
        self::assertIsString($payload['error']);
        self::assertStringContainsStringIgnoringCase('administrator', $payload['error']);
    }

    #[Test]
    public function runActionRunsLoopAndReturnsTrace(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)

        // An in-memory configuration whose model carries a provider, mirroring
        // LlmServiceManagerToolsConfigurationTest: the manager resolves the
        // adapter from the configuration's model, which the mock intercepts.
        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('nr_tools_vault_key');

        $model = new Model();
        $model->setModelId('priced-model-x');
        $model->setProvider($provider);
        $model->setCostInputDollars(1.5);
        $model->setCostOutputDollars(3.0);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-tools');
        $configuration->setLlmModel($model);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($configuration);

        // The scripted adapter asks for one tool call, then answers plainly.
        $adapter         = new ScriptedToolAdapter();
        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([]);

        $manager = new LlmServiceManager(
            $extensionConfig,
            new NullLogger(),
            $adapterRegistry,
            new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
        );

        $toolRegistry    = new ToolRegistry([new FakeTool('fetch_logs')]);
        $toolLoopService = new ToolLoopService($manager, $toolRegistry, $this->availabilityFor($toolRegistry), new NullLogger());

        $controller = $this->makeController($configurationRepository, $toolRegistry, $toolLoopService);

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/run'))
            ->withParsedBody(['configuration' => 1, 'prompt' => 'Show me the last logs.']);
        $response = $controller->runAction($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);

        // The loop ran the tool once and synthesised a final answer.
        self::assertTrue($payload['success']);
        self::assertSame('Here are your recent logs.', $payload['finalContent']);
        self::assertSame(2, $payload['iterations']);
        self::assertFalse($payload['truncated']);

        // The trace records the single executed tool invocation, shape verbatim.
        self::assertIsArray($payload['trace']);
        self::assertCount(1, $payload['trace']);
        $entry = $payload['trace'][0];
        self::assertIsArray($entry);
        self::assertSame('fetch_logs', $entry['name']);
        self::assertSame(['limit' => 5], $entry['arguments']);
        self::assertSame('ok', $entry['result']);
        self::assertFalse($entry['isError']);

        // Token usage is summed across both round-trips (7+3 then 5+4 => 19).
        self::assertIsArray($payload['usage']);
        self::assertSame(19, $payload['usage']['totalTokens']);
    }

    /**
     * Build the controller with the two AJAX-only collaborators supplied by the
     * caller; the ModuleTemplate stack and PageRenderer come from the container.
     */
    private function makeController(
        LlmConfigurationRepository $configurationRepository,
        ToolRegistry $toolRegistry,
        ToolLoopService $toolLoopService,
    ): ToolPlaygroundController {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        $skillRepository = $this->get(SkillRepository::class);
        self::assertInstanceOf(SkillRepository::class, $skillRepository);
        $promptSnippetRepository = $this->get(PromptSnippetRepository::class);
        self::assertInstanceOf(PromptSnippetRepository::class, $promptSnippetRepository);

        return new ToolPlaygroundController(
            $moduleTemplateFactory,
            $configurationRepository,
            $pageRenderer,
            $toolLoopService,
            $this->availabilityFor($toolRegistry),
            $this->get(AllowedToolsResolver::class),
            $skillRepository,
            $promptSnippetRepository,
        );
    }

    /**
     * Build the real availability service over the given registry, backed by the
     * functional database — so the fail-closed gate is exercised end-to-end.
     */
    private function availabilityFor(ToolRegistry $toolRegistry): ToolAvailabilityService
    {
        return new ToolAvailabilityService($toolRegistry, new ToolStateRepository($this->toolConnectionPool()));
    }

    private function toolConnectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        return $connectionPool;
    }

    /**
     * Build an Extbase backend request carrying the route package name so the
     * BackendViewFactory resolves the extension's template root path.
     */
    private function createBackendRequest(): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\ToolPlayground');
        $extbaseParameters->setControllerActionName('list');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/playground', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $reflection->getProperty($property)->setValue($object, $value);
    }
}
