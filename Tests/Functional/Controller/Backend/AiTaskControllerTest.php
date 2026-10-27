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
use Netresearch\NrLlm\Service\Budget\UserBudgetStatusService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Task\TaskCostEstimator;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Netresearch\NrLlm\Service\Task\TaskRunBudgetPreflight;
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

    private const MANUAL_TASK_UID = 1;

    /** An editor holding tasks_use, with a personal budget. */
    private const BUDGET_EDITOR = 5;

    /** Another editor holding tasks_use; their numbers must never show up for BUDGET_EDITOR. */
    private const OTHER_EDITOR = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');
        $this->importFixture('BeUsers.csv');
        $this->insertBudgetFixture();

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
        $body = (string) $response->getBody();
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
        self::assertStringNotContainsString('Test Manual Task', (string) $response->getBody());
    }

    #[Test]
    public function listActionShowsTheEditorsOwnUsageAgainstTheirLimitsAndNoOneElses(): void
    {
        $this->logInBackendUser(self::BUDGET_EDITOR);

        $controller = $this->makeController();
        $this->setRequest($controller, 'list');

        $body = (string) $controller->listAction()->getBody();

        self::assertStringContainsString('data-budget-panel', $body);
        // Own usage: 3 requests and 700 tokens today, ~$0.43 of the $0.45
        // monthly cost limit (0.4321 rounds to 0.43).
        self::assertMatchesRegularExpression('#data-budget-meter="today\.requests">\s*<span id="nrllm-budget-today-requests"><span data-budget-used>3</span> of 10</span>#', $body);
        self::assertMatchesRegularExpression('#data-budget-meter="today\.tokens">\s*<span data-budget-used>700</span>#', $body);
        self::assertStringContainsString('~$<span data-budget-used>0.43</span> of $0.45', $body);
        self::assertMatchesRegularExpression('#<progress class="nrllm-progress" max="100" value="96" data-budget-progress#', $body);
        // The other editor's usage (98,765 tokens) and the unattributed CLI
        // usage on be_user 0 (44,444 tokens) are not this editor's numbers.
        self::assertStringNotContainsString('98,765', $body);
        self::assertStringNotContainsString('44,444', $body);
        self::assertStringNotContainsString('6.79', $body);
        self::assertStringNotContainsString('5.56', $body);
    }

    #[Test]
    public function listActionSaysWhenNoPersonalLimitIsSetAndStillShowsUsage(): void
    {
        $this->logInBackendUser(self::OTHER_EDITOR);

        $controller = $this->makeController();
        $this->setRequest($controller, 'list');

        $body = (string) $controller->listAction()->getBody();

        self::assertStringContainsString('No personal limit is set for you.', $body);
        self::assertStringContainsString('<span data-budget-used>98,765</span>', $body);
        self::assertStringNotContainsString('<progress', $body);
        self::assertStringContainsString('(no limit)', $body);
    }

    #[Test]
    public function executeFormShowsTheEstimateRangeAndTheVerdictAtTheUpperEstimate(): void
    {
        $this->logInBackendUser(self::BUDGET_EDITOR);

        $controller = $this->makeController();
        $this->setRequest($controller, 'executeForm');

        $body = (string) $controller->executeFormAction(self::MANUAL_TASK_UID)->getBody();

        // Configuration 1: model GPT-5 at $10 / $30 per million, max 1000
        // output tokens. Low = input only (a few dozen tokens), high adds
        // 1000 x $30 / 1M = $0.03.
        self::assertMatchesRegularExpression('#<span data-estimate-range>~\$0\.000\d &ndash; ~\$0\.030\d</span>#', $body);
        self::assertStringContainsString('without your input', $body);
        // 0.4321 used + 0.0303 upper estimate > 0.45 monthly limit, while
        // 0.4321 alone is within it: the run starts but may exceed it.
        self::assertStringContainsString('data-state="exceedsAtUpperEstimate"', $body);
        self::assertStringContainsString('it would exceed this limit: your monthly cost.', $body);
        self::assertStringContainsString('data-budget-panel', $body);
    }

    #[Test]
    public function executeFormNamesTheLimitThatRefusesTheNextRun(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_service_usage')
            ->update('tx_nrllm_service_usage', ['request_count' => 10], ['be_user' => self::BUDGET_EDITOR]);
        $this->logInBackendUser(self::BUDGET_EDITOR);

        $controller = $this->makeController();
        $this->setRequest($controller, 'executeForm');

        $body = (string) $controller->executeFormAction(self::MANUAL_TASK_UID)->getBody();

        self::assertStringContainsString('data-state="denied"', $body);
        self::assertStringContainsString('will be refused. This limit is reached: your daily number of requests.', $body);
    }

    #[Test]
    public function budgetStatusActionReturnsOnlyTheLoggedInUsersNumbers(): void
    {
        $this->logInBackendUser(self::BUDGET_EDITOR);

        $response = $this->makeController()->budgetStatusAction(
            (new ServerRequest())->withParsedBody(['uid' => self::MANUAL_TASK_UID, 'beUser' => self::OTHER_EDITOR]),
        );

        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertTrue($json['success']);
        $status = $json['status'] ?? null;
        self::assertIsArray($status);
        $today = $status['today'] ?? null;
        self::assertIsArray($today);
        $month = $status['month'] ?? null;
        self::assertIsArray($month);
        $verdict = $json['verdict'] ?? null;
        self::assertIsArray($verdict);
        self::assertSame(['used' => '700', 'limit' => '0', 'limited' => false, 'percent' => 0], $today['tokens']);
        self::assertSame(['used' => '0.43', 'limit' => '0.45', 'limited' => true, 'percent' => 96], $month['cost']);
        self::assertSame('exceedsAtUpperEstimate', $verdict['state']);
        self::assertStringNotContainsString('98,765', (string) $response->getBody());
    }

    #[Test]
    public function budgetStatusActionAnswersATableTaskLikeAnUnknownOne(): void
    {
        $this->logInBackendUser(self::BUDGET_EDITOR);

        $response = $this->makeController()->budgetStatusAction(
            (new ServerRequest())->withParsedBody(['uid' => self::TABLE_TASK_UID]),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('status', (string) $response->getBody());
    }

    #[Test]
    public function budgetStatusActionDeniesAGrantlessUser(): void
    {
        $this->logInBackendUser(2);

        $response = $this->makeController()->budgetStatusAction(
            (new ServerRequest())->withParsedBody(['uid' => self::MANUAL_TASK_UID]),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('status', (string) $response->getBody());
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Two tasks_use editors and the usage of three be_user values today:
     * BUDGET_EDITOR (with a budget), OTHER_EDITOR (without) and 0, where the
     * usage of callers without a backend user lands.
     */
    private function insertBudgetFixture(): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid'            => 30,
            'pid'            => 0,
            'title'          => 'Task users',
            'custom_options' => 'tx_nrllm_grants:tasks_use',
        ]);
        foreach ([self::BUDGET_EDITOR => 'budgeteditor', self::OTHER_EDITOR => 'othereditor'] as $uid => $username) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid'       => $uid,
                'pid'       => 0,
                'username'  => $username,
                'password'  => '$2y$12$RJxT/2Y6d3oRm5d5ohJi9.CmZlVW.7N4vMHXiQ/D6xJBPfqfNMpBm',
                'admin'     => 0,
                'usergroup' => '30',
            ]);
        }

        $pool->getConnectionForTable('tx_nrllm_user_budget')->insert('tx_nrllm_user_budget', [
            'uid'                  => 50,
            'pid'                  => 0,
            'be_user'              => self::BUDGET_EDITOR,
            'max_requests_per_day' => 10,
            'max_cost_per_month'   => 0.45,
            'is_active'            => 1,
        ]);

        $today = strtotime('today');
        foreach ([
            [self::BUDGET_EDITOR, 3, 700, 0.4321],
            [self::OTHER_EDITOR, 77, 98765, 6.789],
            [0, 12, 44444, 5.555],
        ] as [$beUser, $requests, $tokens, $cost]) {
            $pool->getConnectionForTable('tx_nrllm_service_usage')->insert('tx_nrllm_service_usage', [
                'service_type'      => 'chat',
                'service_provider'  => 'openai',
                'configuration_uid' => 1,
                'be_user'           => $beUser,
                'request_count'     => $requests,
                'tokens_used'       => $tokens,
                'estimated_cost'    => $cost,
                'request_date'      => $today,
            ]);
        }
    }

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
            $this->getService(UserBudgetStatusService::class),
            $this->getService(TaskCostEstimator::class),
            $this->getService(TaskRunBudgetPreflight::class),
            $this->getService(LlmServiceManagerInterface::class),
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
