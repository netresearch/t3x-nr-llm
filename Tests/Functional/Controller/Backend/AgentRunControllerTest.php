<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\AgentRunController;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\SchemaInputCoercer;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
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
 * Renders the approvals inbox through the real ModuleTemplate + Fluid stack and
 * exercises the 422 in-place re-render — the authoritative template render path
 * (ADR-109).
 */
#[CoversClass(AgentRunController::class)]
#[CoversClass(WaitingRunViewFactory::class)]
final class AgentRunControllerTest extends AbstractFunctionalTestCase
{
    private AgentRunPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1); // admin
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $this->persister = new AgentRunPersister($this->repository(), FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
    }

    #[Test]
    public function listActionRendersApprovalAndInputCards(): void
    {
        $this->suspendApproval('delete_thing', ['uid' => 42]);
        $this->suspendInput('ask', ['type' => 'object', 'properties' => ['reason' => ['type' => 'string', 'title' => 'Reason']], 'required' => ['reason']]);

        $controller = $this->makeController(new ToolRegistry([new FakeTool('delete_thing')]), $this->createMock(AgentRuntimeInterface::class));
        $this->setRequest($controller, 'list');

        $response = $controller->listAction();
        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        // Landmarks + headings (a11y).
        self::assertStringContainsString('id="waiting-heading"', $body);
        self::assertStringContainsString('id="terminal-heading"', $body);
        // The approval card shows the pending call and an Approve/Deny form.
        self::assertStringContainsString('delete_thing', $body);
        self::assertStringContainsString('name="approve"', $body);
        self::assertStringContainsString('name="turnDigest"', $body);
        // The input card renders a labelled schema field.
        self::assertStringContainsString('name="input[reason]"', $body);
        self::assertStringContainsString('Reason', $body);
        self::assertStringContainsString('<fieldset', $body);
    }

    #[Test]
    public function submitInputWithInvalidDataReRendersInPlaceWith422(): void
    {
        $this->suspendInput('ask', ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']], 'required' => ['reason']]);
        $uuid = $this->lastUuid();

        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('submitInput')->willThrowException(InvalidInputSubmissionException::forRun($uuid));

        $controller = $this->makeController(new ToolRegistry([new FakeTool('ask')]), $runtime);
        $this->setRequest($controller, 'submitInput');

        $response = $controller->submitInputAction($uuid, ['reason' => '']);

        self::assertSame(422, $response->getStatusCode());
        $body = (string)$response->getBody();
        // The error summary is present and focusable, and the run's card is still shown.
        self::assertStringContainsString('input-errors-' . $uuid, $body);
        self::assertStringContainsString('tabindex="-1"', $body);
    }

    // --- helpers -----------------------------------------------------------

    private ?string $lastUuid = null;

    /**
     * @param array<string, mixed> $arguments
     */
    private function suspendApproval(string $tool, array $arguments): void
    {
        $handle = $this->persister->begin(null, 1);
        self::assertNotNull($handle);
        self::assertTrue($this->persister->suspend(
            $handle,
            new SuspendedRunState([], [ToolCall::function('c1', $tool, $arguments)->toArray()], 1, 0, 0),
        ));
        $this->lastUuid = $handle->uuid;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function suspendInput(string $tool, array $schema): void
    {
        $handle = $this->persister->begin(null, 1);
        self::assertNotNull($handle);
        self::assertTrue($this->persister->suspendForInput(
            $handle,
            new SuspendedRunState([], [ToolCall::function('c1', $tool, [])->toArray()], 1, 0, 0, null, [], $tool, $schema),
        ));
        $this->lastUuid = $handle->uuid;
    }

    private function lastUuid(): string
    {
        self::assertNotNull($this->lastUuid);

        return $this->lastUuid;
    }

    private function repository(): AgentRunRepository
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        return new AgentRunRepository($connectionPool, $this->get(AgentStateCodec::class));
    }

    private function makeController(ToolRegistry $registry, AgentRuntimeInterface $runtime): AgentRunController
    {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        return new AgentRunController(
            $moduleTemplateFactory,
            $this->persister,
            new WaitingRunViewFactory($registry, new SchemaPropertyClassifier()),
            new SchemaInputCoercer(new SchemaPropertyClassifier()),
            $runtime,
            $pageRenderer,
        );
    }

    private function setRequest(AgentRunController $controller, string $action): void
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\AgentRun');
        $extbaseParameters->setControllerActionName($action);
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/runs', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest           = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        $reflection    = new ReflectionClass($controller);
        $extbaseRequest = new ExtbaseRequest($serverRequest);
        $reflection->getProperty('request')->setValue($controller, $extbaseRequest);

        // The render-only actions we test (list + the 422 in-place re-render)
        // need the moduleTemplate property. We set it directly rather than via
        // initializeAction(), whose getFlashMessageQueue()/doc-header calls need
        // Extbase controller services a direct-call harness cannot provide — the
        // flash queue and doc menu are display-only and irrelevant to the card
        // markup we assert. Actions that use redirect()/flash are covered by the
        // WaitingRunViewFactory/SchemaInputCoercer unit tests instead.
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $reflection->getProperty('moduleTemplate')->setValue($controller, $moduleTemplateFactory->create($extbaseRequest));
    }
}
