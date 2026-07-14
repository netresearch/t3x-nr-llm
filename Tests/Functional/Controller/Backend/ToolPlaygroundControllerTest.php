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
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\ScriptedToolAdapter;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
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
    use LlmServiceManagerTestFactory;
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
        // Grouped: the group checkbox and the child's group attribution render.
        self::assertStringContainsString('js-toolgroup-select', $body);
        self::assertStringContainsString('data-group="test"', $body);
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

        $manager = $this->createLlmServiceManager(
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

        self::assertFalse($payload['dryRun']);

        // The step trace records both model round-trips and the executed tool.
        self::assertIsArray($payload['steps']);
        $toolStep = null;
        $llmSteps = 0;
        $kinds    = [];
        foreach ($payload['steps'] as $step) {
            self::assertIsArray($step);
            $kinds[] = $step['kind'] ?? '';
            if (($step['kind'] ?? '') === 'tool') {
                $toolStep = $step;
            } elseif (($step['kind'] ?? '') === 'llm') {
                ++$llmSteps;
            } elseif (($step['kind'] ?? '') === 'request') {
                // The outbound half carries the messages; the response does not.
                self::assertIsArray($step['messagesSent'] ?? null);
            }
        }
        self::assertSame(2, $llmSteps);
        // Each round's request precedes its response; the tool runs in between.
        self::assertSame(['request', 'llm', 'tool', 'request', 'llm'], $kinds);
        self::assertIsArray($toolStep);
        self::assertSame('fetch_logs', $toolStep['toolName']);
        self::assertSame(['limit' => 5], $toolStep['toolArguments']);
        self::assertSame('ok', $toolStep['toolResult']);
        self::assertFalse($toolStep['toolIsError']);

        // Token usage is summed across both round-trips (7+3 then 5+4 => 19).
        self::assertIsArray($payload['usage']);
        self::assertSame(19, $payload['usage']['totalTokens']);
    }

    #[Test]
    public function runActionReturnsValidJsonWhenTheTraceCarriesInvalidUtf8(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('nr_tools_vault_key');

        $model = new Model();
        $model->setModelId('priced-model-x');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-tools');
        $configuration->setLlmModel($model);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($configuration);

        // The model answer carries raw non-UTF-8 bytes (as injected skill prose
        // or a provider echo can). Before the fix the controller's JsonResponse
        // threw and 500'd the whole run; now the bytes are substituted and the
        // inspector still renders.
        $adapter         = new ScriptedToolAdapter("Recent logs: \xFF\xFE done");
        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([]);

        $manager = $this->createLlmServiceManager(
            $extensionConfig,
            new NullLogger(),
            $adapterRegistry,
            new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
        );

        $toolRegistry    = new ToolRegistry([new FakeTool('fetch_logs')]);
        $toolLoopService = new ToolLoopService($manager, $toolRegistry, $this->availabilityFor($toolRegistry), new NullLogger());
        $controller      = $this->makeController($configurationRepository, $toolRegistry, $toolLoopService);

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/run'))
            ->withParsedBody(['configuration' => 1, 'prompt' => 'analyse the logs']);
        $response = $controller->runAction($request);

        // The run must not 500 on the malformed byte, and the body must be
        // valid, decodable JSON with the bytes substituted (U+FFFD).
        self::assertSame(200, $response->getStatusCode());
        $raw = (string)$response->getBody();
        self::assertTrue(mb_check_encoding($raw, 'UTF-8'), 'response body must be valid UTF-8');
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
        self::assertIsString($payload['finalContent']);
        self::assertStringContainsString('Recent logs:', $payload['finalContent']);
    }

    #[Test]
    public function runActionDryRunAssemblesWithoutCallingTheProvider(): void
    {
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);

        // A manager that fails the test if the loop ever calls the provider.
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->expects(self::never())->method('chatWithToolsForConfiguration');

        $toolRegistry = new ToolRegistry([new FakeTool('fetch_logs')]);
        $toolLoop     = new ToolLoopService($manager, $toolRegistry, $this->availabilityFor($toolRegistry), new NullLogger());
        $controller   = $this->makeController($configurationRepository, $toolRegistry, $toolLoop);

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/run'))
            ->withParsedBody(['configuration' => 1, 'prompt' => 'assemble only', 'dryRun' => '1']);
        $response = $controller->runAction($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
        self::assertTrue($payload['dryRun']);
        self::assertSame(0, $payload['iterations']);
        self::assertIsArray($payload['steps']);
        self::assertCount(1, $payload['steps']);
        $firstStep = $payload['steps'][0];
        self::assertIsArray($firstStep);
        self::assertSame('assembled', $firstStep['kind']);
    }

    #[Test]
    public function runActionForwardsMaxTokensAndTemperatureToTheProvider(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('nr_tools_vault_key');

        $model = new Model();
        $model->setModelId('priced-model-x');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-tools');
        $configuration->setLlmModel($model);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($configuration);

        $adapter         = new ScriptedToolAdapter();
        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([]);

        $manager = $this->createLlmServiceManager(
            $extensionConfig,
            new NullLogger(),
            $adapterRegistry,
            new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
        );

        $toolRegistry    = new ToolRegistry([new FakeTool('fetch_logs')]);
        $toolLoopService = new ToolLoopService($manager, $toolRegistry, $this->availabilityFor($toolRegistry), new NullLogger());
        $controller      = $this->makeController($configurationRepository, $toolRegistry, $toolLoopService);

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/run'))
            ->withParsedBody([
                'configuration' => 1,
                'prompt'        => 'analyse the logs',
                'maxTokens'     => '4096',
                'temperature'   => '0.1',
            ]);
        $response = $controller->runAction($request);

        self::assertSame(200, $response->getStatusCode());
        // The per-run overrides reached the adapter as real provider options.
        self::assertSame(4096, $adapter->lastOptions['max_tokens'] ?? null);
        self::assertSame(0.1, $adapter->lastOptions['temperature'] ?? null);
    }

    /**
     * Build the controller with the two AJAX-only collaborators supplied by the
     * caller; the ModuleTemplate stack and PageRenderer come from the container.
     */
    #[Test]
    public function runActionClampsOutOfRangeTemperatureInsteadOf500(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        [$controller] = $this->scriptedController();

        // temperature 5.0 is outside ToolOptions' 0.0–2.0 range; unclamped it
        // would throw an uncaught InvalidArgumentException (a 500), not a run.
        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/run'))
            ->withParsedBody(['configuration' => 1, 'prompt' => 'go', 'temperature' => '5']);
        $response = $controller->runAction($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
    }

    #[Test]
    public function streamRunEmitsAStepPerRecordedStepThenDone(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        [$controller, $config] = $this->scriptedController();

        $events = [];
        $emit   = static function (array $event) use (&$events): void {
            $events[] = $event;
        };

        // Exercise the transport-free protocol directly (runStreamed's echo/flush
        // tears down output buffering, which can't run inside PHPUnit).
        $method = new ReflectionMethod($controller, 'streamRun');
        $method->invoke(
            $controller,
            $emit,
            [ChatMessage::user('analyse the logs')],
            $config,
            ['fetch_logs'],
            new ToolOptions(),
            null,
            new RunAugmentation(),
            false,
        );

        $kinds = array_map(static fn(array $e): mixed => $e['event'] ?? null, $events);
        // One 'step' per recorded step, then a terminal 'done'.
        self::assertContains('step', $kinds);
        self::assertGreaterThanOrEqual(5, count(array_filter($kinds, static fn(mixed $k): bool => $k === 'step')));
        self::assertNotEmpty($events);

        // The very first event is the outbound request — emitted BEFORE the
        // provider call, which is what makes the inspector live from second
        // zero — and the full interleaving matches the loop.
        $stepKinds = [];
        foreach ($events as $event) {
            if (($event['event'] ?? null) === 'step' && is_array($event['step'] ?? null)) {
                $stepKinds[] = $event['step']['kind'] ?? '';
            }
        }
        self::assertSame(['request', 'llm', 'tool', 'request', 'llm'], $stepKinds);

        $last = end($events);
        self::assertIsArray($last);
        self::assertSame('done', $last['event']);
        self::assertTrue($last['success']);
        self::assertSame('Here are your recent logs.', $last['finalContent']);
        self::assertIsArray($last['usage']);
    }

    /**
     * Build a controller wired to the scripted tool adapter (one tool call, then
     * a plain answer) over an in-memory configuration.
     *
     * @return array{0: ToolPlaygroundController, 1: LlmConfiguration}
     */
    private function scriptedController(string $finalContent = 'Here are your recent logs.'): array
    {
        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('nr_tools_vault_key');

        $model = new Model();
        $model->setModelId('priced-model-x');
        $model->setProvider($provider);

        $config = new LlmConfiguration();
        $config->setIdentifier('cfg-tools');
        $config->setLlmModel($model);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($config);

        $adapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn(new ScriptedToolAdapter($finalContent));

        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([]);

        $manager = $this->createLlmServiceManager(
            $extensionConfig,
            new NullLogger(),
            $adapterRegistry,
            new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
        );

        $toolRegistry    = new ToolRegistry([new FakeTool('fetch_logs')]);
        $toolLoopService = new ToolLoopService($manager, $toolRegistry, $this->availabilityFor($toolRegistry), new NullLogger());

        return [$this->makeController($configurationRepository, $toolRegistry, $toolLoopService), $config];
    }

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
        return new ToolAvailabilityService($toolRegistry, new ToolStateRepository($this->toolConnectionPool()), new ToolGroupStateRepository($this->toolConnectionPool()));
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
