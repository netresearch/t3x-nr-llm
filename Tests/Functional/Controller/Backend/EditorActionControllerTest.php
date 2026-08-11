<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\EditorActionController;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOffer;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOfferGroup;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchPlanner;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogue;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeEditorActionTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder as ExtbaseUriBuilder;
use TYPO3\CMS\Extbase\Service\ExtensionService;

/**
 * The Editor Action Center rendered and driven through the real ModuleTemplate +
 * Fluid stack (ADR-158).
 *
 * The catalogue seam is a double here on purpose: what it decides is asserted in
 * {@see \Netresearch\NrLlm\Tests\Unit\Service\Tool\EditorActionCatalogueTest}
 * against the real tool gate's answer. What this test owns is the surface — that
 * the declaration is rendered translated rather than as a wire name, that a
 * start form appears only with a record, that the grant is enforced, and that
 * starting one produces exactly one ordinary agent run.
 */
#[CoversClass(EditorActionController::class)]
#[CoversClass(EditorActionCatalogue::class)]
#[CoversClass(EditorActionBatchPlanner::class)]
final class EditorActionControllerTest extends AbstractFunctionalTestCase
{
    private const TOOL = 'update_page_metadata';

    /**
     * The tool the two access tests below enable and offer. A double rather
     * than a shipped writer: what those tests decide is the configuration's
     * `beGroups` axis, so the tool must not be what makes them pass or fail.
     */
    private const WRITE_TOOL = 'fake_write';

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function catalogueActionDeniesABackendUserWithoutTheGrant(): void
    {
        $this->signIn(2); // non-admin, no tasks_use grant

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        // The grant is checked before anything is asked, so an unauthorised
        // request never reaches the catalogue at all.
        $catalogue->expects(self::never())->method('groupsFor');

        $controller = $this->makeController($catalogue, $this->neverRunningRuntime());
        $this->setRequest($controller, 'catalogue');

        self::assertSame(403, $controller->catalogueAction('pages', 42)->getStatusCode());
    }

    #[Test]
    public function startActionDeniesABackendUserWithoutTheGrant(): void
    {
        $this->signIn(2);

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->expects(self::never())->method('runRequestFor');

        $controller = $this->makeController($catalogue, $this->neverRunningRuntime());
        $this->setRequest($controller, 'start');

        self::assertSame(403, $controller->startAction(self::TOOL, 'pages', 42)->getStatusCode());
    }

    #[Test]
    public function catalogueActionRendersTheDeclarationTranslatedAndAStartFormForTheRecord(): void
    {
        $this->signIn(1); // admin holds every grant implicitly

        $controller = $this->makeController($this->catalogueOffering(), $this->neverRunningRuntime());
        $this->setRequest($controller, 'catalogue');

        $response = $controller->catalogueAction('pages', 42);
        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        // The group's translated name, and the action's own name and sentence —
        // never the wire name or the model-facing description (ADR-152).
        self::assertStringContainsString('Editing', $body);
        self::assertStringContainsString('Update page metadata', $body);
        self::assertStringContainsString('Sets descriptive text fields on one page', $body);
        // The subject travels into the form so the run knows what it addresses.
        self::assertStringContainsString('value="' . self::TOOL . '"', $body);
        self::assertStringContainsString('name="recordTable"', $body);
        self::assertStringContainsString('value="42"', $body);
        self::assertStringContainsString('name="instruction"', $body);
    }

    #[Test]
    public function catalogueActionWithoutARecordOffersNoStartForm(): void
    {
        $this->signIn(1);

        $controller = $this->makeController($this->catalogueOffering(), $this->neverRunningRuntime());
        $this->setRequest($controller, 'catalogue');

        $body = (string)$controller->catalogueAction()->getBody();

        // The action is listed — an editor can see what exists — but an action
        // needs a subject, and this module has no record picker.
        self::assertStringContainsString('Update page metadata', $body);
        self::assertStringNotContainsString('name="instruction"', $body);
        self::assertStringContainsString('Select a record to start this action.', $body);
    }

    #[Test]
    public function startActionRunsTheRequestTheCatalogueBuiltAndRedirectsToTheInbox(): void
    {
        $this->signIn(1);

        $runRequest = $this->runRequest();
        $catalogue  = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->method('runRequestFor')->willReturn($runRequest);

        $seen    = null;
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::once())->method('run')->willReturnCallback(
            static function (AgentRunRequest $request) use (&$seen): AgentRunResult {
                $seen = $request;

                // What a declared write does: it pauses before it touches
                // anything (ADR-134).
                return new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-uuid', []);
            },
        );

        $controller = $this->makeController($catalogue, $runtime);
        $this->setRequest($controller, 'start');

        $response = $controller->startAction(self::TOOL, 'pages', 42, 'shorter abstract');

        self::assertSame(303, $response->getStatusCode());
        self::assertInstanceOf(AgentRunRequest::class, $seen);
        // The identity is the whole assertion: the controller hands the
        // catalogue's object through untouched. What that object contains is
        // EditorActionCatalogueTest's subject, not this one's.
        self::assertSame($runRequest, $seen);
        self::assertSame(
            ['The action is waiting for approval. Review it in the approvals inbox.'],
            $this->flashMessages(),
        );
    }

    /**
     * A POST is not a permission: the catalogue is asked again, and a refusal
     * means no run at all.
     */
    #[Test]
    public function startActionStartsNothingForAnActionTheCatalogueDoesNotOffer(): void
    {
        $this->signIn(1);

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->method('runRequestFor')->willReturn(null);

        $controller = $this->makeController($catalogue, $this->neverRunningRuntime());
        $this->setRequest($controller, 'start');

        $response = $controller->startAction('no_such_action', 'pages', 42);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            ['This action is not available for that record.'],
            $this->flashMessages(),
        );
    }

    #[Test]
    public function startActionSaysNothingWasWrittenWhenTheRunEndsWithoutAPause(): void
    {
        $this->signIn(1);

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->method('runRequestFor')->willReturn($this->runRequest());

        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturn(new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-uuid', []));

        $controller = $this->makeController($catalogue, $runtime);
        $this->setRequest($controller, 'start');

        self::assertSame(303, $controller->startAction(self::TOOL, 'pages', 42)->getStatusCode());
        self::assertSame(
            ['The run finished without proposing a change. Nothing was written.'],
            $this->flashMessages(),
        );
    }

    /**
     * The control for the test below: a member of the group the default
     * configuration is restricted to is offered the action and can start it.
     * Without this the refusal would prove nothing — an empty catalogue is also
     * what a misconfigured fixture produces.
     */
    #[Test]
    public function offersTheActionToAnEditorInsideTheConfigurationsBackendGroups(): void
    {
        $this->signInRestricted(3);

        $catalogue  = $this->realCatalogue();
        $runtime    = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::once())->method('run')->willReturn(
            new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-uuid', []),
        );

        $controller = $this->makeController($catalogue, $runtime);
        $this->setRequest($controller, 'catalogue');
        self::assertStringContainsString('Update page metadata', (string)$controller->catalogueAction('pages', 42)->getBody());

        $this->setRequest($controller, 'start');
        self::assertSame(303, $controller->startAction(self::WRITE_TOOL, 'pages', 42)->getStatusCode());
    }

    /**
     * ADR-070: `beGroups` on a configuration means only those backend groups may
     * use it. The tool gate does not answer that axis, so the catalogue asks it
     * — and both the rendered offer and the POST that names the action directly
     * have to obey it. The editor here holds `tasks_use` and differs from the
     * member above in exactly one thing: their group.
     */
    #[Test]
    public function offersNothingAndStartsNothingForAnEditorOutsideTheConfigurationsBackendGroups(): void
    {
        $this->signInRestricted(4);

        $catalogue  = $this->realCatalogue();
        $controller = $this->makeController($catalogue, $this->neverRunningRuntime());

        $this->setRequest($controller, 'catalogue');
        $response = $controller->catalogueAction('pages', 42);
        // Not a 403: the grant is held. The page renders and lists nothing.
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Update page metadata', (string)$response->getBody());

        // A POST is not a permission either: the runtime mock refuses to run.
        $this->setRequest($controller, 'start');
        self::assertSame(303, $controller->startAction(self::WRITE_TOOL, 'pages', 42)->getStatusCode());
        self::assertContains('This action is not available for that record.', $this->flashMessages());
    }

    // --- bulk (ADR-162) ----------------------------------------------------

    #[Test]
    public function batchActionsDenyABackendUserWithoutTheGrant(): void
    {
        $this->signIn(2); // non-admin, no tasks_use grant

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->expects(self::never())->method('runRequestFor');

        $controller = $this->makeController($catalogue, $this->neverRunningRuntime());

        $this->setRequest($controller, 'batch');
        self::assertSame(403, $controller->batchAction(self::TOOL, 'pages', '1,2')->getStatusCode());

        $this->setRequest($controller, 'startBatch');
        self::assertSame(403, $controller->startBatchAction(self::TOOL, 'pages', '1,2')->getStatusCode());
    }

    #[Test]
    public function batchActionRendersThePlanAndTheEstimateWithoutStartingAnything(): void
    {
        $this->signIn(1);

        $controller = $this->makeController($this->catalogueOffering([41]), $this->neverRunningRuntime());
        $this->setRequest($controller, 'batch');

        $response = $controller->batchAction(self::TOOL, 'pages', '41, 42');
        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        // The action is named to a human, never by its wire name (ADR-152).
        self::assertStringContainsString('Update page metadata', $body);
        // Both records appear — the one that will run and the one that will not,
        // with the reason next to it.
        self::assertStringContainsString('#41', $body);
        self::assertStringContainsString('#42', $body);
        self::assertStringContainsString('This action is not available to you for this record.', $body);
        // And the estimate, derived from the one request the plan holds.
        self::assertStringContainsString('Provider requests (at least)', $body);
        self::assertStringContainsString('How wrong this can be', $body);
    }

    #[Test]
    public function startBatchActionStartsOneOrdinaryRunPerRecordAndLandsInTheInbox(): void
    {
        $this->signIn(1);

        $seen    = [];
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(
            static function (AgentRunRequest $request) use (&$seen): AgentRunResult {
                $seen[] = $request;

                return new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-uuid', []);
            },
        );

        $controller = $this->makeController($this->catalogueOffering([41, 42, 43]), $runtime);
        $this->setRequest($controller, 'startBatch');

        $response = $controller->startBatchAction(self::TOOL, 'pages', '41 42 43');

        self::assertSame(303, $response->getStatusCode());
        // Three records, three runs — not one run with three calls.
        self::assertCount(3, $seen);
        foreach ($seen as $request) {
            self::assertSame([self::TOOL], $request->allowedToolNames);
        }

        self::assertContains(
            '3 actions were started. Each one is waiting for its own approval in the inbox.',
            $this->flashMessages(),
        );
    }

    /**
     * A record the catalogue refuses is reported by number. The failure this
     * guards is the quiet one: a batch that starts two of three runs and says
     * three.
     */
    #[Test]
    public function startBatchActionNamesTheRecordsItSkipped(): void
    {
        $this->signIn(1);

        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::once())->method('run')->willReturn(
            new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-uuid', []),
        );

        $controller = $this->makeController($this->catalogueOffering([41]), $runtime);
        $this->setRequest($controller, 'startBatch');

        self::assertSame(303, $controller->startBatchAction(self::TOOL, 'pages', '41,42,43,41,nonsense')->getStatusCode());

        $messages = $this->flashMessages();
        self::assertContains('This action is not available to you for this record. Records skipped: 42, 43', $messages);
        self::assertContains('Named more than once and planned only once. Records skipped: 41', $messages);
        self::assertContains('1 entries were not record numbers and were ignored.', $messages);
    }

    /**
     * The half-done batch this design does not pretend away (ADR-162): N runs
     * hit the budget N times, so it can run out partway. What must not happen is
     * silence about the records that never started.
     *
     * The denied run is settled the way the RUNTIME settles one, not the way a
     * denial reads: {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
     * catches the exception and returns a truncated result, which the executor
     * settles as COMPLETED with no `error` — asserted in
     * AgentRuntimeTest::aBudgetExhaustedLoopResultSettlesCompletedAndCarriesTheReason.
     * A guard that watched `error` would never fire here.
     */
    #[Test]
    public function startBatchActionStopsAtTheBudgetAndNamesWhatItNeverStarted(): void
    {
        $this->signIn(1);

        $calls   = 0;
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(
            static function () use (&$calls): AgentRunResult {
                ++$calls;

                if ($calls === 1) {
                    return new AgentRunResult(AgentRunOutcome::AWAITING_APPROVAL, 'run-uuid', []);
                }

                return new AgentRunResult(
                    AgentRunOutcome::COMPLETED,
                    'run-uuid',
                    [],
                    loopResult: new ToolLoopResult(
                        '',
                        [],
                        1,
                        true,
                        UsageStatistics::fromTokens(3, 0),
                        AgentRunTerminationReason::BUDGET_EXHAUSTED,
                    ),
                );
            },
        );

        $controller = $this->makeController($this->catalogueOffering([41, 42, 43, 44]), $runtime);
        $this->setRequest($controller, 'startBatch');

        self::assertSame(303, $controller->startBatchAction(self::TOOL, 'pages', '41,42,43,44')->getStatusCode());

        // One started, one denied, and the two after it never attempted.
        self::assertSame(2, $calls);
        $messages = $this->flashMessages();
        self::assertContains('1 actions were started. Each one is waiting for its own approval in the inbox.', $messages);
        // Record 42 IS the one that ran into the budget, so it is not among the
        // never-started — and it is not counted as a run that merely proposed
        // nothing either.
        self::assertContains(
            'The AI budget for your account ran out, so the batch stopped. Nothing was written for the record it stopped on. These records were never started: 43, 44',
            $messages,
        );
        self::assertNotContains('1 runs finished without proposing a change. Nothing was written for those records.', $messages);
    }

    /**
     * A denial on the LAST record names nobody, and must still say the budget
     * ran out. The earlier shape folded both facts into one sentence, so an
     * empty list silenced the whole message.
     */
    #[Test]
    public function startBatchActionReportsABudgetStopOnTheLastRecordWithNothingLeftToName(): void
    {
        $this->signIn(1);

        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturn(new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            'run-uuid',
            [],
            loopResult: new ToolLoopResult('', [], 1, true, UsageStatistics::fromTokens(3, 0), AgentRunTerminationReason::BUDGET_EXHAUSTED),
        ));

        $controller = $this->makeController($this->catalogueOffering([41]), $runtime);
        $this->setRequest($controller, 'startBatch');

        self::assertSame(303, $controller->startBatchAction(self::TOOL, 'pages', '41')->getStatusCode());

        self::assertContains(
            'The AI budget for your account ran out, so the batch stopped. Nothing was written for the record it stopped on.',
            $this->flashMessages(),
        );
    }

    /**
     * A budget denial that DOES arrive as a throwable — the no-tools branch,
     * where the send sits outside the loop's own catch and the exception
     * propagates to the generic failure arm.
     */
    #[Test]
    public function startBatchActionStopsAtABudgetDenialThatArrivesAsAWrappedThrowable(): void
    {
        $this->signIn(1);

        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturn(new AgentRunResult(
            AgentRunOutcome::FAILED,
            '',
            [],
            // Wrapped, as a middleware layer may hand it back: the whole chain
            // is walked, not the outermost type.
            error: new RuntimeException('run failed', 0, new BudgetExceededException(
                BudgetCheckResult::denied('daily_cost', 12.0, 10.0),
            )),
        ));

        $controller = $this->makeController($this->catalogueOffering([41, 42]), $runtime);
        $this->setRequest($controller, 'startBatch');

        self::assertSame(303, $controller->startBatchAction(self::TOOL, 'pages', '41,42')->getStatusCode());

        self::assertContains(
            'The AI budget for your account ran out, so the batch stopped. Nothing was written for the record it stopped on. These records were never started: 42',
            $this->flashMessages(),
        );
    }

    /**
     * A batch where everything failed must not read like a batch where nothing
     * needed changing. The single-record path distinguishes these outcomes; the
     * batch report is held to the same line.
     */
    #[Test]
    public function startBatchActionReportsFailedAndBlockedRunsApartFromQuietOnes(): void
    {
        $this->signIn(1);

        $outcomes = [
            AgentRunOutcome::FAILED,
            AgentRunOutcome::SUSPEND_FAILED,
            AgentRunOutcome::GUARDRAIL_BLOCKED,
            AgentRunOutcome::COMPLETED,
        ];

        $calls   = 0;
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('run')->willReturnCallback(
            static function () use (&$calls, $outcomes): AgentRunResult {
                return new AgentRunResult($outcomes[$calls++], 'run-uuid', []);
            },
        );

        $controller = $this->makeController($this->catalogueOffering([41, 42, 43, 44]), $runtime);
        $this->setRequest($controller, 'startBatch');

        self::assertSame(303, $controller->startBatchAction(self::TOOL, 'pages', '41,42,43,44')->getStatusCode());

        $messages = $this->flashMessages();
        // SUSPEND_FAILED joins FAILED: an approval was required and could not be
        // stored, so no resume is possible — the sharpest case of all.
        self::assertContains('2 runs failed. Nothing was written for those records, and none of them is waiting for approval.', $messages);
        self::assertContains('1 runs were stopped by a guardrail. Nothing was written for those records.', $messages);
        self::assertContains('1 runs finished without proposing a change. Nothing was written for those records.', $messages);
    }

    #[Test]
    public function startBatchActionStartsNothingForRecordsTheCatalogueDoesNotOffer(): void
    {
        $this->signIn(1);

        $controller = $this->makeController($this->catalogueOffering([]), $this->neverRunningRuntime());
        $this->setRequest($controller, 'startBatch');

        // Back to the plan rather than to the inbox: nothing is waiting there.
        self::assertSame(303, $controller->startBatchAction(self::TOOL, 'pages', '41,42')->getStatusCode());
        self::assertContains(
            'This action is not available to you for this record. Records skipped: 41, 42',
            $this->flashMessages(),
        );
    }

    // --- helpers -----------------------------------------------------------

    private function signIn(int $uid): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * A non-admin who holds `tasks_use`, in a world whose ONE active default
     * configuration is restricted to backend group 20. User 3 is in it, user 4
     * is not; nothing else separates them.
     */
    private function signInRestricted(int $uid): void
    {
        $this->importFixture('EditorActionAccess.csv');
        $backendUser     = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * The real catalogue over a real gate and the real configuration
     * repository — the doubles everywhere else in this class would decide the
     * access question instead of observing it.
     */
    private function realCatalogue(): EditorActionCatalogue
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $registry        = new ToolRegistry([new FakeEditorActionTool(self::WRITE_TOOL)]);
        $stateRepository = new ToolStateRepository($connectionPool);
        // Every writer ships disabled, so the action only exists once an
        // administrator has enabled it.
        $stateRepository->setEnabled(self::WRITE_TOOL, true);

        $availability = new ToolAvailabilityService(
            $registry,
            $stateRepository,
            new ToolGroupStateRepository($connectionPool),
        );

        $enforcement = $this->get(DataClassEnforcementResolver::class);
        self::assertInstanceOf(DataClassEnforcementResolver::class, $enforcement);

        $policy = new ToolCallPolicy(
            $registry,
            $availability,
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            $enforcement,
        );

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $configurations = $this->get(LlmConfigurationServiceInterface::class);
        self::assertInstanceOf(LlmConfigurationServiceInterface::class, $configurations);

        return new EditorActionCatalogue($availability, $policy, $configurationRepository, $configurations);
    }

    /**
     * A catalogue that offers the one action.
     *
     * `$runnableUids` additionally stubs the per-record question the batch loop
     * asks: those record numbers get a run request, every other number gets the
     * refusal a real catalogue would give. Null leaves `runRequestFor()`
     * unstubbed, for the single-record tests that arrange it themselves.
     *
     * @param list<int>|null $runnableUids
     */
    private function catalogueOffering(?array $runnableUids = null): EditorActionCatalogueInterface
    {
        $offer = new EditorActionOffer(
            self::TOOL,
            new EditorAction(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.label',
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.description',
                'nrllm-editor-action-page-metadata',
                ['pages'],
            ),
            'editing',
        );

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->method('groupsFor')->willReturn([
            new EditorActionOfferGroup(
                'editing',
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:tool.group.editing',
                [$offer],
            ),
        ]);

        if ($runnableUids !== null) {
            $catalogue->method('runRequestFor')->willReturnCallback(
                static function (string $toolName, string $recordTable, int $recordUid) use ($runnableUids): ?AgentRunRequest {
                    if (!in_array($recordUid, $runnableUids, true)) {
                        return null;
                    }

                    return new AgentRunRequest(
                        configuration: new LlmConfiguration(),
                        messages: [ChatMessage::user(sprintf('Call "%s" for %s #%d.', $toolName, $recordTable, $recordUid))],
                        actor: AiActorContext::backendUser(1, true),
                        allowedToolNames: [$toolName],
                    );
                },
            );
        }

        return $catalogue;
    }

    private function runRequest(): AgentRunRequest
    {
        return new AgentRunRequest(
            configuration: new LlmConfiguration(),
            messages: [],
            actor: AiActorContext::backendUser(1, true),
            allowedToolNames: [self::TOOL],
        );
    }

    private function neverRunningRuntime(): AgentRuntimeInterface
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::never())->method('run');

        return $runtime;
    }

    /**
     * The rendered text of every flash message the action queued.
     *
     * @return list<string>
     */
    private function flashMessages(): array
    {
        $flashMessageService = $this->get(FlashMessageService::class);
        self::assertInstanceOf(FlashMessageService::class, $flashMessageService);
        $extensionService = $this->get(ExtensionService::class);
        self::assertInstanceOf(ExtensionService::class, $extensionService);

        $queue = $flashMessageService->getMessageQueueByIdentifier(
            'extbase.flashmessages.' . $extensionService->getPluginNamespace('NrLlm', null),
        );

        return array_values(array_map(
            static fn(FlashMessage $message): string => $message->getMessage(),
            $queue->getAllMessagesAndFlush(),
        ));
    }

    private function makeController(EditorActionCatalogueInterface $catalogue, AgentRuntimeInterface $runtime): EditorActionController
    {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);

        // The planner is real, over the same catalogue double: it owns no
        // authorisation of its own (ADR-162), so a double would only hide the
        // loop this class exercises.
        $planner = new EditorActionBatchPlanner(
            $catalogue,
            new ToolRegistry([new FakeEditorActionTool(self::TOOL)]),
            new TranscriptEstimator(),
        );

        return new EditorActionController($moduleTemplateFactory, $catalogue, $runtime, $planner);
    }

    /**
     * The direct-call harness proven by {@see AgentRunControllerTest}: an
     * Extbase backend request plus the three collaborators an ActionController
     * normally receives through its inject* methods, so the POST-redirect-GET
     * actions are reachable too.
     */
    private function setRequest(EditorActionController $controller, string $action): void
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\EditorAction');
        $extbaseParameters->setControllerActionName($action);
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/web/nrllm-aitasks', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        $extbaseRequest = new ExtbaseRequest($serverRequest);
        $reflection     = new ReflectionClass($controller);
        $reflection->getProperty('request')->setValue($controller, $extbaseRequest);

        $uriBuilder = $this->get(ExtbaseUriBuilder::class);
        self::assertInstanceOf(ExtbaseUriBuilder::class, $uriBuilder);
        $uriBuilder->setRequest($extbaseRequest);
        $reflection->getProperty('uriBuilder')->setValue($controller, $uriBuilder);

        // Private on ActionController, so reachable only through the declaring
        // class rather than the subclass reflection above.
        $flashMessageService = $this->get(FlashMessageService::class);
        self::assertInstanceOf(FlashMessageService::class, $flashMessageService);
        (new ReflectionProperty(ActionController::class, 'internalFlashMessageService'))->setValue($controller, $flashMessageService);

        $extensionService = $this->get(ExtensionService::class);
        self::assertInstanceOf(ExtensionService::class, $extensionService);
        (new ReflectionProperty(ActionController::class, 'internalExtensionService'))->setValue($controller, $extensionService);
    }
}
