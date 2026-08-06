<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\AiTaskController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Service\ExtensionService;

/**
 * Renders the editor task module (ADR-131) through the real ModuleTemplate +
 * Fluid stack: the list must filter out TABLE-input tasks (no record-picker
 * read boundary yet, ADR-130), the execute form must bounce a TABLE task's
 * uid with a redirect instead of a form, and the grant gate must answer a
 * grantless non-admin with the HTML 403.
 *
 * The controller is instantiated directly with container services and its
 * Extbase request is set via reflection — the module-route dispatch itself is
 * not exercised (same harness as {@see AgentRunControllerTest}).
 */
#[CoversClass(AiTaskController::class)]
final class AiTaskControllerTest extends AbstractFunctionalTestCase
{
    private const TABLE_TASK_UID = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');
        $this->importFixture('BeUsers.csv');

        // Tasks.csv carries no TABLE-input task; add an ACTIVE one so the
        // filter has something real to hide.
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_task')->insert('tx_nrllm_task', [
            'uid'               => self::TABLE_TASK_UID,
            'pid'               => 0,
            'identifier'        => 'test-table-task',
            'name'              => 'Test Table Task',
            'description'       => 'An active task reading records from a table',
            'category'          => 'general',
            'configuration_uid' => 1,
            'prompt_template'   => 'Analyze: {{input}}',
            'input_type'        => 'table',
            'input_source'      => '{"table":"pages"}',
            'output_format'     => 'markdown',
            'is_active'         => 1,
        ]);
    }

    #[Test]
    public function listActionShowsActiveManualTasksButNeverTableOnes(): void
    {
        $this->logInBackendUser(1); // admin passes the grant gate

        $controller = $this->makeController();
        $this->setRequest($controller, 'list');

        $response = $controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        // The active MANUAL task is offered ...
        self::assertStringContainsString('Test Manual Task', $body);
        // ... the active TABLE task is filtered out (ADR-130: no record-picker
        // read boundary yet), as are inactive and deleted tasks.
        self::assertStringNotContainsString('Test Table Task', $body);
        self::assertStringNotContainsString('Test Inactive Task', $body);
        self::assertStringNotContainsString('Test Deleted Task', $body);
    }

    #[Test]
    public function executeFormActionRedirectsForATableTaskInsteadOfRenderingTheForm(): void
    {
        $this->logInBackendUser(1);

        $controller = $this->makeController();
        $this->setRequest($controller, 'executeForm');

        $response = $controller->executeFormAction(self::TABLE_TASK_UID);

        // Same bounce as an unknown uid — a guessed uid leaks nothing.
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(302, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('location'));
        // The user gets an explanation via the flash queue.
        $messages = $this->getService(FlashMessageService::class)
            ->getMessageQueueByIdentifier('extbase.flashmessages.tx_nrllm_')
            ->getAllMessagesAndFlush();
        self::assertNotEmpty($messages);
    }

    #[Test]
    public function listActionAnswersAGrantlessNonAdminWithAnHtmlForbidden(): void
    {
        $this->logInBackendUser(2); // editor without groups: no tasks_use, no agent_approve

        $controller = $this->makeController();
        $this->setRequest($controller, 'list');

        $response = $controller->listAction();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('Test Manual Task', (string)$response->getBody());
    }

    // --- helpers -----------------------------------------------------------

    private function logInBackendUser(int $uid): void
    {
        $backendUser     = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function makeController(): AiTaskController
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $controller = new AiTaskController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(TaskRepository::class),
            $this->getService(LlmConfigurationRepository::class),
            $this->getService(TaskInputResolverInterface::class),
            $this->getService(BackendUriBuilder::class),
            $this->getService(PageRenderer::class),
        );

        // getFlashMessageQueue()/addFlashMessage() need the two internal
        // Extbase controller services a direct construction skips.
        $controller->injectInternalFlashMessageService($this->getService(FlashMessageService::class));
        $controller->injectInternalExtensionService($this->getService(ExtensionService::class));

        return $controller;
    }

    private function setRequest(AiTaskController $controller, string $action): void
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\AiTask');
        $extbaseParameters->setControllerActionName($action);
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/web/nrllm-aitasks', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        $reflection     = new ReflectionClass($controller);
        $extbaseRequest = new ExtbaseRequest($serverRequest);
        $reflection->getProperty('request')->setValue($controller, $extbaseRequest);
    }
}
