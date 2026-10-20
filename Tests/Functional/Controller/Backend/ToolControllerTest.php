<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest as GuzzleServerRequest;
use Netresearch\NrLlm\Controller\Backend\ToolController;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeEditorActionTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeMalformedEditorActionTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Functional tests for the admin-only Tools management backend module.
 *
 * Renders listAction() through the real ModuleTemplate stack and asserts each
 * registered tool surfaces with its description and an enable/disable control.
 * The AJAX toggleToolAction is covered by the admin-guard, happy-path and
 * unknown-tool tests below.
 */
#[CoversClass(ToolController::class)]
#[CoversClass(ToolStateRepository::class)]
#[CoversClass(ToolGroupStateRepository::class)]
#[CoversClass(ToolAvailabilityService::class)]
final class ToolControllerTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function listActionRendersToolManagementList(): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $toolRegistry = new ToolRegistry([new FakeTool('fetch_logs')]);
        $controller   = $this->makeController($toolRegistry);
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $response = $controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        // The management list shows the tool name, its description and the toggle button.
        self::assertStringContainsString('fetch_logs', $body);
        self::assertStringContainsString('desc of fetch_logs', $body);
        self::assertStringContainsString('js-tool-toggle', $body);
        // The playground run form (config picker) does NOT live here anymore.
        self::assertStringNotContainsString('id="nrllm-tool-config"', $body);
    }

    #[Test]
    public function toggleToolActionDeniesNonAdmin(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2); // uid 2 is a non-admin editor (admin=0)

        $controller = $this->makeController(new ToolRegistry([new FakeTool('fetch_logs')]));

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'fetch_logs', 'enabled' => '0']);
        $response = $controller->toggleToolAction($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
    }

    #[Test]
    public function toggleToolActionRejectsUnknownTool(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin

        $controller = $this->makeController(new ToolRegistry([new FakeTool('fetch_logs')]));

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'no_such_tool', 'enabled' => '0']);
        $response = $controller->toggleToolAction($request);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
    }

    #[Test]
    public function toggleToolActionPersistsDisabledState(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin

        $toolRegistry = new ToolRegistry([new FakeTool('fetch_logs')]);
        $controller   = $this->makeController($toolRegistry);

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'fetch_logs', 'enabled' => '0']);
        $response = $controller->toggleToolAction($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
        self::assertFalse($payload['enabled']);

        // The fail-closed gate now reports the tool as disabled on every read.
        self::assertNotContains('fetch_logs', $this->availabilityFor($toolRegistry)->enabledNames());
    }

    #[Test]
    public function listActionRendersGroupHeadersAndGroupToggle(): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $toolRegistry = new ToolRegistry([
            new FakeTool('alpha_tool', group: 'alpha'),
            new FakeTool('beta_tool', group: 'beta'),
        ]);
        $controller = $this->makeController($toolRegistry);
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $body = (string) $controller->listAction()->getBody();

        self::assertStringContainsString('js-toolgroup-toggle', $body);
        self::assertStringContainsString('data-group="alpha"', $body);
        self::assertStringContainsString('data-group="beta"', $body);
    }

    /**
     * The declaration ADR-152 introduces is only worth having if the module
     * renders it, so this asserts the rendered HTML rather than the state array:
     * the translated action name and group name instead of a wire identifier,
     * the human sentence instead of the model-facing description, and the
     * applicable record type.
     */
    #[Test]
    public function listActionRendersADeclaredEditorActionInsteadOfItsWireName(): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $controller = $this->makeController(new ToolRegistry([new FakeEditorActionTool('write_tool')]));
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $body = (string) $controller->listAction()->getBody();

        // The group header carries the curated group's translated name.
        self::assertStringContainsString('Editing', $body);
        // The action's own name and human sentence, both from the catalogue.
        self::assertStringContainsString('Update page metadata', $body);
        self::assertStringContainsString('Sets descriptive text fields on one page', $body);
        // The record type it applies to, and the icon that illustrates it.
        // Both markup-anchored: a bare "pages" also occurs in the module chrome,
        // and would pass with the record-type list removed.
        self::assertStringContainsString('<code class="text-break">pages</code>', $body);
        self::assertStringContainsString('icon-nrllm-editor-action-page-metadata', $body);
        // The model-facing description is NOT what an administrator reads here.
        self::assertStringNotContainsString('model-facing description of write_tool', $body);
        // The wire name stays VISIBLE as the technical detail — asserted as the
        // rendered text, not as the toggle's data-tool attribute, which is there
        // for every tool whether or not the wire name is shown.
        self::assertStringContainsString('<code class="text-break">write_tool</code>', $body);
    }

    /**
     * A third-party declaration that throws is a rendering defect and stays one
     * (ADR-152): the module renders, the tool is listed under its wire name and
     * a sound declaration beside it is still decorated.
     */
    #[Test]
    public function listActionRendersAToolWhoseDeclarationThrowsAsAnUndeclaredOne(): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $controller = $this->makeController(new ToolRegistry([
            new FakeMalformedEditorActionTool('broken_tool'),
            new FakeEditorActionTool('write_tool'),
        ]));
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $response = $controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        // The broken row: wire name and model-facing description, as for any
        // tool that declares nothing.
        self::assertStringContainsString('<code class="text-break">broken_tool</code>', $body);
        self::assertStringContainsString('model-facing description of broken_tool', $body);
        self::assertStringContainsString('data-tool="broken_tool"', $body);
        // The sound declaration beside it is unaffected.
        self::assertStringContainsString('Update page metadata', $body);
    }

    #[Test]
    public function toggleToolGroupActionDeniesNonAdmin(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2); // non-admin editor

        $controller = $this->makeController(new ToolRegistry([new FakeTool('t', group: 'alpha')]));
        $request    = (new GuzzleServerRequest('POST', '/ajax/nrllm/toolgroup/toggle'))
            ->withParsedBody(['group' => 'alpha', 'enabled' => 'false']);

        self::assertSame(403, $controller->toggleToolGroupAction($request)->getStatusCode());
    }

    #[Test]
    public function toggleToolGroupActionRejectsUnknownGroup(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $controller = $this->makeController(new ToolRegistry([new FakeTool('t', group: 'alpha')]));
        $request    = (new GuzzleServerRequest('POST', '/ajax/nrllm/toolgroup/toggle'))
            ->withParsedBody(['group' => 'nope', 'enabled' => 'false']);

        self::assertSame(404, $controller->toggleToolGroupAction($request)->getStatusCode());
    }

    #[Test]
    public function toggleToolGroupActionPersistsAndCascades(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $toolRegistry = new ToolRegistry([
            new FakeTool('alpha_tool', group: 'alpha'),
            new FakeTool('beta_tool', group: 'beta'),
        ]);
        $controller = $this->makeController($toolRegistry);
        $request    = (new GuzzleServerRequest('POST', '/ajax/nrllm/toolgroup/toggle'))
            ->withParsedBody(['group' => 'alpha', 'enabled' => 'false']);

        $response = $controller->toggleToolGroupAction($request);
        self::assertSame(200, $response->getStatusCode());

        // The cascade drops the whole group from the enabled set.
        self::assertSame(['beta_tool'], $this->availabilityFor($toolRegistry)->enabledNames());
    }

    private function makeController(ToolRegistry $toolRegistry): ToolController
    {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        return new ToolController(
            $moduleTemplateFactory,
            $toolRegistry,
            $this->availabilityFor($toolRegistry),
            new ToolStateRepository($this->toolConnectionPool()),
            new ToolGroupStateRepository($this->toolConnectionPool()),
            $pageRenderer,
        );
    }

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
        $extbaseParameters->setControllerName('Backend\Tool');
        $extbaseParameters->setControllerActionName('list');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/tools', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $reflection->getProperty($property)->setValue($object, $value);
    }

    #[Test]
    #[DataProvider('toggleBodyValues')]
    public function toggleToolActionReplacesTheOppositePersistedOverride(
        mixed $submitted,
        bool $expected,
    ): void {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        $registry = new ToolRegistry(
            [
                new FakeTool('focus', enabledByDefault: false, group: 'alpha'),
                new FakeTool('alpha_on', group: 'alpha'),
                new FakeTool('beta_on', group: 'beta'),
            ],
        );
        $pool = $this->toolConnectionPool();
        (new ToolStateRepository($pool))->setEnabled('focus', !$expected);
        $connection = $pool->getConnectionForTable('tx_nrllm_tool_state');
        $originalUid = $connection
            ->select(['uid'], 'tx_nrllm_tool_state', ['tool_name' => 'focus'])
            ->fetchOne();
        $response = $this
            ->makeController($registry)
            ->toggleToolAction(
                (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))->withParsedBody(
                    ['tool' => 'focus', 'enabled' => $submitted],
                ),
            );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['success' => true, 'enabled' => $expected],
            json_decode(
                (string)$response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
        $rows = $connection
            ->select(
                ['uid', 'tool_name', 'enabled'],
                'tx_nrllm_tool_state',
                ['tool_name' => 'focus'],
            )
            ->fetchAllAssociative();
        self::assertCount(
            1,
            $rows,
            'Replacing an existing override must not duplicate its SQL row.',
        );
        self::assertSame($originalUid, $rows[0]['uid']);
        self::assertSame('focus', $rows[0]['tool_name']);
        self::assertIsNumeric($rows[0]['enabled']);
        self::assertSame($expected ? 1 : 0, (int)$rows[0]['enabled']);
        self::assertSame(
            ['focus' => $expected],
            (new ToolStateRepository($pool))->overrides(),
        );
        self::assertSame(
            $expected ? ['focus', 'alpha_on', 'beta_on'] : ['alpha_on', 'beta_on'],
            $this->availabilityFor($registry)->enabledNames(),
        );
        self::assertSame([], (new ToolGroupStateRepository($pool))->overrides());
    }

    #[Test]
    #[DataProvider('toggleBodyValues')]
    public function toggleToolGroupActionReplacesTheOverrideWithoutLiftingIndividualDenials(
        mixed $submitted,
        bool $expected,
    ): void {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        $registry = new ToolRegistry(
            [
                new FakeTool('alpha_default_on', group: 'alpha'),
                new FakeTool(
                    'alpha_default_off',
                    enabledByDefault: false,
                    group: 'alpha',
                ),
                new FakeTool(
                    'alpha_override',
                    enabledByDefault: false,
                    group: 'alpha',
                ),
                new FakeTool('beta_on', group: 'beta'),
            ],
        );
        $pool = $this->toolConnectionPool();
        (new ToolGroupStateRepository($pool))->setEnabled('alpha', !$expected);
        (new ToolStateRepository($pool))->setEnabled('alpha_override', true);
        $connection = $pool->getConnectionForTable('tx_nrllm_tool_group_state');
        $originalUid = $connection
            ->select(['uid'], 'tx_nrllm_tool_group_state', ['group_name' => 'alpha'])
            ->fetchOne();
        $response = $this
            ->makeController($registry)
            ->toggleToolGroupAction(
                (new GuzzleServerRequest('POST', '/ajax/nrllm/toolgroup/toggle'))->withParsedBody(
                    ['group' => 'alpha', 'enabled' => $submitted],
                ),
            );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['success' => true, 'enabled' => $expected],
            json_decode(
                (string)$response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
        $rows = $connection
            ->select(
                ['uid', 'group_name', 'enabled'],
                'tx_nrllm_tool_group_state',
                ['group_name' => 'alpha'],
            )
            ->fetchAllAssociative();
        self::assertCount(
            1,
            $rows,
            'Replacing an existing group override must retain one SQL row.',
        );
        self::assertSame($originalUid, $rows[0]['uid']);
        self::assertSame('alpha', $rows[0]['group_name']);
        self::assertIsNumeric($rows[0]['enabled']);
        self::assertSame($expected ? 1 : 0, (int)$rows[0]['enabled']);
        self::assertSame(
            ['alpha' => $expected],
            (new ToolGroupStateRepository($pool))->overrides(),
        );
        self::assertSame(
            ['alpha_override' => true],
            (new ToolStateRepository($pool))->overrides(),
        );
        self::assertSame(
            $expected ? ['alpha_default_on', 'alpha_override', 'beta_on'] : ['beta_on'],
            $this->availabilityFor($registry)->enabledNames(),
        );
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function toggleBodyValues(): iterable
    {
        yield 'json-true' => [true, true];
        yield 'integer-one' => [1, true];
        yield 'form-one' => ['1', true];
        yield 'form-true' => ['true', true];
        yield 'json-false' => [false, false];
        yield 'integer-zero' => [0, false];
        yield 'form-zero' => ['0', false];
        yield 'form-false' => ['false', false];
        yield 'null' => [null, false];
        yield 'array' => [[], false];
    }
}
