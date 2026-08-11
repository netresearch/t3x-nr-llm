<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\EditorActionController;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOffer;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOfferGroup;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
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

    private function catalogueOffering(): EditorActionCatalogueInterface
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

        return new EditorActionController($moduleTemplateFactory, $catalogue, $runtime);
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
