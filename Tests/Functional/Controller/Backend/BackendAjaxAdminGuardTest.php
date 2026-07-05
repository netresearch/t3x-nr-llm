<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Controller\Backend\SetupWizardController;
use Netresearch\NrLlm\Controller\Backend\TaskExecutionController;
use Netresearch\NrLlm\Controller\Backend\TaskRecordsController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;

/**
 * Verifies that every newly-guarded backend AJAX endpoint refuses a
 * non-admin backend user with HTTP 403 and performs no state mutation.
 *
 * The nrllm backend module is registered ``access => admin``, but the
 * standalone AJAX routes in ``Configuration/Backend/AjaxRoutes.php`` are
 * dispatched outside the module route and bypass that check. The shared
 * RequiresBackendAdminTrait closes that gap (ADR-037). This test mirrors the
 * non-admin pattern from {@see SkillSourceControllerTest}: a non-admin BE user
 * plus a backend request, controller resolved via the container, asserting 403.
 *
 * One representative action per guarded controller is exercised here; the
 * remaining actions share the identical first-line guard.
 */
#[CoversClass(ProviderController::class)]
#[CoversClass(ModelController::class)]
#[CoversClass(ConfigurationController::class)]
#[CoversClass(TaskRecordsController::class)]
#[CoversClass(TaskExecutionController::class)]
#[CoversClass(SetupWizardController::class)]
#[CoversClass(LlmModuleController::class)]
final class BackendAjaxAdminGuardTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');

        // uid 2 is a non-admin editor (admin=0).
        $this->setUpBackendUser(2);

        // Resolving an Extbase ActionController from the container triggers
        // injectConfigurationManager(), which reads settings from the current
        // request. Provide a backend request so resolution succeeds.
        $GLOBALS['TYPO3_REQUEST'] = (new Typo3ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * Assert a denied (403, success:false) JSON response.
     */
    private function assertForbidden(ResponseInterface $response): void
    {
        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
        // The denial carries an actionable, admin-oriented message (translated;
        // falls back to the English source) rather than a bare "Forbidden".
        self::assertIsString($payload['error']);
        self::assertStringContainsStringIgnoringCase('administrator', $payload['error']);
    }

    #[Test]
    public function providerToggleActiveDeniedForNonAdmin(): void
    {
        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);
        $before = $providerRepository->findByUid(1);
        self::assertNotNull($before);
        self::assertTrue($before->isActive());

        $controller = $this->get(ProviderController::class);
        self::assertInstanceOf(ProviderController::class, $controller);

        $request = (new ServerRequest('POST', '/ajax/nrllm/provider/toggle-active'))
            ->withParsedBody(['uid' => 1]);
        $this->assertForbidden($controller->toggleActiveAction($request));

        // The denied call must not have toggled the provider.
        $reloaded = $providerRepository->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isActive(), 'a denied toggle must leave the provider active');
    }

    #[Test]
    public function modelSetDefaultDeniedForNonAdmin(): void
    {
        $modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $modelRepository);
        $before = $modelRepository->findByUid(3);
        self::assertNotNull($before);
        self::assertFalse($before->isDefault());

        $controller = $this->get(ModelController::class);
        self::assertInstanceOf(ModelController::class, $controller);

        $request = (new ServerRequest('POST', '/ajax/nrllm/model/set-default'))
            ->withParsedBody(['uid' => 3]);
        $this->assertForbidden($controller->setDefaultAction($request));

        // The denied call must not have promoted model 3 to default.
        $reloaded = $modelRepository->findByUid(3);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isDefault(), 'a denied set-default must not change the default model');
    }

    #[Test]
    public function configurationSetDefaultDeniedForNonAdmin(): void
    {
        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $before = $configurationRepository->findByUid(2);
        self::assertNotNull($before);
        self::assertFalse($before->isDefault());

        $controller = $this->get(ConfigurationController::class);
        self::assertInstanceOf(ConfigurationController::class, $controller);

        $request = (new ServerRequest('POST', '/ajax/nrllm/config/set-default'))
            ->withParsedBody(['uid' => 2]);
        $this->assertForbidden($controller->setDefaultAction($request));

        // The denied call must not have promoted configuration 2 to default.
        $reloaded = $configurationRepository->findByUid(2);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isDefault(), 'a denied set-default must not change the default configuration');
    }

    #[Test]
    public function taskRecordsFetchRecordsDeniedForNonAdmin(): void
    {
        $controller = $this->get(TaskRecordsController::class);
        self::assertInstanceOf(TaskRecordsController::class, $controller);

        $request = (new ServerRequest('POST', '/ajax/nrllm/task/fetch-records'))
            ->withParsedBody(['table' => 'tx_nrllm_task']);
        // Reading arbitrary records is admin-only; a non-admin must be refused
        // before any table read happens.
        $this->assertForbidden($controller->fetchRecordsAction($request));
    }

    #[Test]
    public function taskExecuteDeniedForNonAdmin(): void
    {
        $controller = $this->get(TaskExecutionController::class);
        self::assertInstanceOf(TaskExecutionController::class, $controller);

        // Task uid=1 is active in the fixture; a non-admin must not be able to
        // execute it (no LLM call, no execution).
        $request = (new ServerRequest('POST', '/ajax/nrllm/task/execute'))
            ->withParsedBody(['uid' => 1, 'input' => 'should not run']);
        $this->assertForbidden($controller->executeAction($request));
    }

    #[Test]
    public function setupWizardGenerateDeniedForNonAdmin(): void
    {
        $controller = $this->get(SetupWizardController::class);
        self::assertInstanceOf(SetupWizardController::class, $controller);

        $request = (new ServerRequest('POST', '/ajax/nrllm/wizard/generate'))
            ->withParsedBody([
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-should-not-be-used',
                'models' => [['modelId' => 'gpt-5', 'name' => 'GPT-5']],
            ]);
        $this->assertForbidden($controller->generateAction($request));
    }

    #[Test]
    public function llmModuleExecuteTestDeniedForNonAdmin(): void
    {
        $controller = $this->get(LlmModuleController::class);
        self::assertInstanceOf(LlmModuleController::class, $controller);

        // The guard short-circuits before $this->request is touched, so the
        // non-admin call is refused without dispatching an Extbase request.
        $this->assertForbidden($controller->executeTestAction());
    }

    #[Test]
    public function llmModuleReachabilityDeniedForNonAdmin(): void
    {
        $controller = $this->get(LlmModuleController::class);
        self::assertInstanceOf(LlmModuleController::class, $controller);

        // The overview reachability probe is admin-only: the guard refuses a
        // non-admin before any provider is contacted.
        $this->assertForbidden($controller->reachabilityAction());
    }
}
