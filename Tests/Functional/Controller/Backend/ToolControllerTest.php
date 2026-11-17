<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Error;
use GuzzleHttp\Psr7\ServerRequest as GuzzleServerRequest;
use InvalidArgumentException;
use Netresearch\NrLlm\Controller\Backend\ToolController;
use Netresearch\NrLlm\Domain\Enum\ToolAuditScope;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Tool\ToolAuditRepository;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolExclusionReadout;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolProviderInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\EditorActionDiagnosticLogger;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FailingToolAuditRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeEditorActionTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeMalformedEditorActionTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ToolRegistryDiagnosticLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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
#[CoversClass(ToolAuditRepository::class)]
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
    public function listActionNamesTheConfigurationsThatExcludeATool(): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', [
            'uid'            => 731,
            'pid'            => 0,
            'identifier'     => 'editorial-chat',
            'name'           => 'Editorial chat',
            'is_active'      => 1,
            'excluded_tools' => 'delete_record',
        ]);

        $controller = $this->makeController(
            new ToolRegistry([new FakeTool('delete_record'), new FakeTool('move_page')]),
            new ToolExclusionReadout($this->getService(LlmConfigurationRepository::class)),
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $body = (string) $controller->listAction()->getBody();

        self::assertSame(1, substr_count($body, 'Excluded by'), 'only the excluded tool carries the note');
        self::assertMatchesRegularExpression('/Excluded by.*Editorial chat \\(<code class="text-break">editorial-chat<\\/code>\\).*data-tool="delete_record".*data-tool="move_page"/s', $body);
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

    /**
     * The controller the backend runs (built by the container, so the audit
     * dependency is the wired one) leaves one audit row per real switch:
     * who, when, which tool, from what to what. A repeated identical switch
     * changes nothing and leaves no row.
     */
    #[Test]
    public function toggleToolActionRecordsWhoSwitchedWhichToolWhenAndFromWhatToWhat(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin
        $controller = $this->containerController();
        $disable    = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'fetch_logs', 'enabled' => '0']);

        $before = time();
        self::assertSame(200, $controller->toggleToolAction($disable)->getStatusCode());
        $after = time();

        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame('tool', $rows[0]['scope']);
        self::assertSame('fetch_logs', $rows[0]['name']);
        self::assertSame(1, $rows[0]['old_enabled'], 'fetch_logs is enabled by default');
        self::assertSame(0, $rows[0]['new_enabled']);
        self::assertSame(1, $rows[0]['actor_uid']);
        self::assertSame(0, $rows[0]['original_actor_uid']);
        self::assertGreaterThanOrEqual($before, $rows[0]['crdate']);
        self::assertLessThanOrEqual($after, $rows[0]['crdate']);

        // The same switch again changes nothing and records nothing.
        self::assertSame(200, $controller->toggleToolAction($disable)->getStatusCode());
        self::assertCount(1, $this->auditRows());

        $enable = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'fetch_logs', 'enabled' => '1']);
        self::assertSame(200, $controller->toggleToolAction($enable)->getStatusCode());
        $rows = $this->auditRows();
        self::assertCount(2, $rows);
        self::assertSame(['fetch_logs', 0, 1], [$rows[1]['name'], $rows[1]['old_enabled'], $rows[1]['new_enabled']]);
    }

    /**
     * The state before is the tool's own default when there is no override:
     * get_env_raw ships disabled, so enabling it is recorded as 0 -> 1.
     */
    #[Test]
    public function enablingAToolThatShipsDisabledRecordsItsDefaultAsTheStateBefore(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin
        $controller = $this->containerController();

        $response = $controller->toggleToolAction((new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'get_env_raw', 'enabled' => '1']));
        self::assertSame(200, $response->getStatusCode());

        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame(['tool', 'get_env_raw', 0, 1], [$rows[0]['scope'], $rows[0]['name'], $rows[0]['old_enabled'], $rows[0]['new_enabled']]);
    }

    #[Test]
    public function toggleToolGroupActionRecordsWhoSwitchedWhichGroupWhenAndFromWhatToWhat(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin
        $controller = $this->containerController();
        $disable    = (new GuzzleServerRequest('POST', '/ajax/nrllm/toolgroup/toggle'))
            ->withParsedBody(['group' => 'system', 'enabled' => '0']);

        $before = time();
        self::assertSame(200, $controller->toggleToolGroupAction($disable)->getStatusCode());
        $after = time();

        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame('group', $rows[0]['scope']);
        self::assertSame('system', $rows[0]['name']);
        self::assertSame(1, $rows[0]['old_enabled']);
        self::assertSame(0, $rows[0]['new_enabled']);
        self::assertSame(1, $rows[0]['actor_uid']);
        self::assertGreaterThanOrEqual($before, $rows[0]['crdate']);
        self::assertLessThanOrEqual($after, $rows[0]['crdate']);

        self::assertSame(200, $controller->toggleToolGroupAction($disable)->getStatusCode());
        self::assertCount(1, $this->auditRows());
    }

    #[Test]
    public function aRefusedToggleRecordsNothing(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2); // non-admin editor
        $controller = $this->containerController();

        $controller->toggleToolAction((new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'fetch_logs', 'enabled' => '0']));
        $controller->toggleToolGroupAction((new GuzzleServerRequest('POST', '/ajax/nrllm/toolgroup/toggle'))
            ->withParsedBody(['group' => 'system', 'enabled' => '0']));

        self::assertSame([], $this->auditRows());
    }

    /**
     * The switch and its audit row are one transaction: when the audit
     * insert fails, the switch is rolled back and the action reports an
     * error. Otherwise the state would change without a row, and a retry
     * would record nothing because the state already matches.
     */
    #[Test]
    #[DataProvider('switchActions')]
    public function aFailedAuditInsertRollsTheSwitchBack(string $action, string $field, string $name): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin
        $pool         = $this->toolConnectionPool();
        $toolRegistry = new ToolRegistry([new FakeTool('fetch_logs', group: 'system')]);
        $controller   = new ToolController(
            $this->getService(ModuleTemplateFactory::class),
            $toolRegistry,
            $this->availabilityFor($toolRegistry),
            new ToolStateRepository($pool),
            new ToolGroupStateRepository($pool),
            $this->getService(PageRenderer::class),
            audit: new FailingToolAuditRepository(),
            connectionPool: $pool,
        );
        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/toggle'))
            ->withParsedBody([$field => $name, 'enabled' => '0']);

        $response = null;
        try {
            $response = $action === 'tool' ? $controller->toggleToolAction($request) : $controller->toggleToolGroupAction($request);
        } catch (Throwable) {
            // Asserted below: the state must be unchanged either way.
        }

        self::assertSame([], (new ToolStateRepository($pool))->overrides(), 'the tool switch must be rolled back');
        self::assertSame([], (new ToolGroupStateRepository($pool))->overrides(), 'the group switch must be rolled back');
        self::assertInstanceOf(ResponseInterface::class, $response, 'the failure is reported, not thrown');
        self::assertSame(500, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
        self::assertSame([], $this->auditRows());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function switchActions(): iterable
    {
        yield 'tool' => ['tool', 'tool', 'fetch_logs'];
        yield 'group' => ['group', 'group', 'system'];
    }

    /**
     * In switch-user mode the session user is the one switched to; the row
     * names the administrator who switched as well.
     */
    #[Test]
    public function aSwitchInSwitchUserModeNamesTheAdministratorWhoSwitched(): void
    {
        $actor       = self::createStub(BackendUserAuthentication::class);
        $actor->user = ['uid' => 2];
        $actor->method('getOriginalUserIdWhenInSwitchUserMode')->willReturn(1);

        (new ToolAuditRepository($this->toolConnectionPool()))->record(ToolAuditScope::TOOL, 'fetch_logs', true, false, $actor);

        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame([2, 1], [$rows[0]['actor_uid'], $rows[0]['original_actor_uid']]);
    }

    /**
     * The controller as the backend gets it. An Extbase controller from the
     * container needs a backend request in $GLOBALS['TYPO3_REQUEST'].
     */
    private function containerController(): ToolController
    {
        $this->createBackendRequest();

        return $this->getService(ToolController::class);
    }

    /**
     * The audit rows as stored, oldest first, integer columns as integers.
     *
     * @return list<array{scope: string, name: string, old_enabled: int, new_enabled: int, actor_uid: int, original_actor_uid: int, crdate: int}>
     */
    private function auditRows(): array
    {
        $rows = $this->toolConnectionPool()->getConnectionForTable('tx_nrllm_tool_audit')
            ->select(['scope', 'name', 'old_enabled', 'new_enabled', 'actor_uid', 'original_actor_uid', 'crdate'], 'tx_nrllm_tool_audit', [], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();

        $typed = [];
        foreach ($rows as $row) {
            $typed[] = [
                'scope'              => is_string($row['scope']) ? $row['scope'] : '',
                'name'               => is_string($row['name']) ? $row['name'] : '',
                'old_enabled'        => is_numeric($row['old_enabled']) ? (int) $row['old_enabled'] : -1,
                'new_enabled'        => is_numeric($row['new_enabled']) ? (int) $row['new_enabled'] : -1,
                'actor_uid'          => is_numeric($row['actor_uid']) ? (int) $row['actor_uid'] : -1,
                'original_actor_uid' => is_numeric($row['original_actor_uid']) ? (int) $row['original_actor_uid'] : -1,
                'crdate'             => is_numeric($row['crdate']) ? (int) $row['crdate'] : -1,
            ];
        }

        return $typed;
    }

    private function makeController(ToolRegistry $toolRegistry, ?ToolExclusionReadout $exclusions = null): ToolController
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
            $exclusions,
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
    #[DataProvider('registryDiagnosticModes')]
    public function listActionKeepsRealToolRowsWhenProviderDiagnosticsFail(
        string $kind,
        string $mode,
    ): void {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this
            ->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser);
        $cause           = new Error('Imported catalogue row failed.', 1770581117);
        $deliveryFailure = match ($mode) {
            'runtime' => new RuntimeException('Diagnostic delivery failed.', 1770581118),
            'error'   => new Error('Diagnostic delivery failed.', 1770581118),
            default   => null,
        };
        $logger   = $mode === 'none' ? null : new ToolRegistryDiagnosticLogger($deliveryFailure);
        $provider = new class ($kind, $cause) implements ToolProviderInterface {
            public function __construct(
                private readonly string $kind,
                private readonly Throwable $cause,
            ) {}

            public function tools(): iterable
            {
                if ($this->kind !== 'collision') {
                    throw $this->cause;
                }

                yield new FakeTool('builtin');
                yield new FakeTool('same_provider');
            }
        };
        $later = new class implements ToolProviderInterface {
            public function tools(): iterable
            {
                return [new FakeTool('later_provider')];
            }
        };
        $builtin    = new FakeTool('builtin');
        $registry   = new ToolRegistry([$builtin], [$provider, $later], $logger);
        $controller = $this->makeController($registry);
        $this->setPrivateProperty(
            $controller,
            'request',
            $this->createBackendRequest(),
        );
        $response = null;
        $escaped  = null;
        try {
            $response = $controller->listAction();
        } catch (Throwable $error) {
            $escaped = $error;
        }

        self::assertNull(
            $escaped,
            'Optional provider diagnostics must not abort the real Tools module.',
        );
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        foreach (['builtin', 'later_provider'] as $name) {
            self::assertStringContainsString(
                '<code class="text-break">' . $name . '</code>',
                $body,
            );
            self::assertStringContainsString('desc of ' . $name, $body);
            self::assertStringContainsString('data-tool="' . $name . '"', $body);
        }

        if ($kind === 'collision') {
            self::assertStringContainsString(
                '<code class="text-break">same_provider</code>',
                $body,
            );
            self::assertStringContainsString('desc of same_provider', $body);
            self::assertStringContainsString('data-tool="same_provider"', $body);
        } else {
            self::assertStringNotContainsString(
                'data-tool="same_provider"',
                $body,
            );
        }

        self::assertSame($builtin, $registry->get('builtin'));
        self::assertSame(
            $kind === 'collision' ? ['builtin', 'same_provider', 'later_provider'] : ['builtin', 'later_provider'],
            $this->availabilityFor($registry)->enabledNames(),
        );
        if ($logger instanceof ToolRegistryDiagnosticLogger) {
            self::assertSame(
                [
                    $kind === 'collision' ? [
                        'level'   => 'warning',
                        'message' => 'A provided tool was dropped because its name is already taken',
                        'context' => ['tool' => 'builtin', 'provider' => $provider::class],
                    ] : [
                        'level'   => 'error',
                        'message' => 'A tool provider failed and supplied no tools',
                        'context' => ['provider' => $provider::class, 'exception' => $cause],
                    ],
                ],
                $logger->records,
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function registryDiagnosticModes(): iterable
    {
        foreach (['collision', 'provider-failure'] as $kind) {
            foreach (['runtime', 'error', 'recording', 'none'] as $mode) {
                yield $kind . '-' . $mode => [$kind, $mode];
            }
        }
    }

    #[Test]
    #[DataProvider('declarationDiagnosticModes')]
    public function listActionKeepsRealRowsWhenOptionalDeclarationDiagnosticsFail(
        string $mode,
    ): void {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this
            ->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser);
        $diagnosticFailure = match ($mode) {
            'runtime' => new RuntimeException('Optional diagnostic unavailable.'),
            'error'   => new Error('Optional diagnostic unavailable.'),
            default   => null,
        };
        $logger   = $mode === 'none' ? null : new EditorActionDiagnosticLogger($diagnosticFailure);
        $registry = new ToolRegistry(
            [
                new FakeMalformedEditorActionTool('broken_tool'),
                new FakeEditorActionTool('write_tool'),
            ],
        );
        $pool          = $this->toolConnectionPool();
        $moduleFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleFactory);
        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);
        $availability = new ToolAvailabilityService(
            $registry,
            new ToolStateRepository($pool),
            new ToolGroupStateRepository($pool),
            $logger,
        );
        $controller = new ToolController(
            $moduleFactory,
            $registry,
            $availability,
            new ToolStateRepository($pool),
            new ToolGroupStateRepository($pool),
            $pageRenderer,
        );
        $this->setPrivateProperty(
            $controller,
            'request',
            $this->createBackendRequest(),
        );
        $response = null;
        $escaped  = null;
        try {
            $response = $controller->listAction();
        } catch (Throwable $exception) {
            $escaped = $exception;
        }

        self::assertNull(
            $escaped,
            'Optional declaration diagnostics must not replace the real module response.',
        );
        self::assertInstanceOf(
            ResponseInterface::class,
            $response,
        );
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString(
            '<code class="text-break">broken_tool</code>',
            $body,
        );
        self::assertStringContainsString(
            'model-facing description of broken_tool',
            $body,
        );
        self::assertStringContainsString('data-tool="broken_tool"', $body);
        self::assertStringContainsString('Update page metadata', $body);
        self::assertStringContainsString(
            'icon-nrllm-editor-action-page-metadata',
            $body,
        );
        self::assertStringContainsString('data-tool="write_tool"', $body);
        self::assertSame(['broken_tool'], $availability->enabledNames());
        if ($logger instanceof EditorActionDiagnosticLogger) {
            self::assertCount(1, $logger->records);
            self::assertSame('warning', $logger->records[0]['level']);
            self::assertSame(
                'Tool {tool} declares a malformed editor action; rendering it as an undeclared tool.',
                $logger->records[0]['message'],
            );
            self::assertSame(
                ['tool', 'exception'],
                array_keys($logger->records[0]['context']),
            );
            self::assertSame(
                'broken_tool',
                $logger->records[0]['context']['tool'],
            );
            self::assertInstanceOf(
                InvalidArgumentException::class,
                $logger->records[0]['context']['exception'],
            );
            self::assertSame(
                1786406402,
                $logger->records[0]['context']['exception']->getCode(),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declarationDiagnosticModes(): iterable
    {
        foreach (['runtime', 'error', 'recording', 'none'] as $mode) {
            yield $mode => [$mode];
        }
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
        $connection  = $pool->getConnectionForTable('tx_nrllm_tool_state');
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
                (string) $response->getBody(),
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
        self::assertSame($expected ? 1 : 0, (int) $rows[0]['enabled']);
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
        $connection  = $pool->getConnectionForTable('tx_nrllm_tool_group_state');
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
                (string) $response->getBody(),
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
        self::assertSame($expected ? 1 : 0, (int) $rows[0]['enabled']);
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
