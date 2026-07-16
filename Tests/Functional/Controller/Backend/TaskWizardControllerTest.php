<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\TaskWizardController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\WizardGeneratorService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
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
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder as ExtbaseUriBuilder;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The AI task wizard (ADR-027 slice 13e) end to end against the real
 * container: the description form render, the fail-closed validation
 * redirects, and wizardCreateAction()'s persistence chain including the
 * numeric clamps and enum allow-lists on wizard-supplied values.
 *
 * The generation preview actions are exercised through their error path
 * (the fixture provider cannot be reached from the test container), which
 * must degrade into a flash message + redirect, never an exception.
 */
#[CoversClass(TaskWizardController::class)]
final class TaskWizardControllerTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function wizardFormActionRendersDescriptionForm(): void
    {
        $this->setUpFixturesAndUser();
        $controller = $this->createController($this->createBackendRequest());

        $response = $controller->wizardFormAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        self::assertStringContainsString('id="wizard-form"', $body);
        self::assertStringContainsString('id="wizard-description"', $body);
        self::assertStringContainsString('id="wizard-config"', $body);
    }

    #[Test]
    public function wizardGenerateActionWithEmptyDescriptionRedirectsToForm(): void
    {
        $this->setUpFixturesAndUser();
        $controller = $this->createController($this->createBackendRequest());

        $response = $controller->wizardGenerateAction('   ');

        $this->assertRedirectsToWizardForm($response);
    }

    #[Test]
    public function wizardGenerateActionRendersPreviewFromFallbackWhenProviderFails(): void
    {
        $this->setUpFixturesAndUser();
        $controller = $this->createController($this->createBackendRequest());

        // The fixture provider endpoint is unreachable from the test
        // container; WizardGeneratorService degrades to its deterministic
        // fallback task, and the action must still render the preview.
        $response = $controller->wizardGenerateAction('Summarize the weekly error logs');

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        self::assertStringContainsString('Summarize the weekly error logs', $body);
    }

    #[Test]
    public function wizardGenerateChainActionWithEmptyDescriptionRedirectsToForm(): void
    {
        $this->setUpFixturesAndUser();
        $controller = $this->createController($this->createBackendRequest());

        $response = $controller->wizardGenerateChainAction('');

        $this->assertRedirectsToWizardForm($response);
    }

    #[Test]
    public function wizardCreateActionPersistsConfigurationAndTaskWithClampedValues(): void
    {
        $this->setUpFixturesAndUser();
        $request = $this->createBackendRequest([
            'task' => [
                'identifier'      => 'wizard-made-task',
                'name'            => 'Wizard Made Task',
                'description'     => 'Created by the wizard test',
                'category'        => 'not-a-category',
                'prompt_template' => 'Do the thing: {{input}}',
                'output_format'   => 'not-a-format',
            ],
            'configuration' => [
                'identifier'  => 'wizard-made-config',
                'name'        => 'Wizard Made Config',
                'temperature' => '5',
                'max_tokens'  => '999999999',
                'top_p'       => '9',
            ],
            'model_choice'       => 'existing',
            'existing_model_uid' => '1',
            'config_choice'      => 'new',
        ]);
        $controller = $this->createController($request);

        $response = $controller->wizardCreateAction();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('nrllm', $response->getHeaderLine('location'));

        $connection = $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_task');

        $task = $connection->select(
            ['*'],
            'tx_nrllm_task',
            ['identifier' => 'wizard-made-task'],
        )->fetchAssociative();
        self::assertIsArray($task);
        // Unknown enum values fall back to their defaults.
        self::assertSame('general', $task['category']);
        self::assertSame('markdown', $task['output_format']);
        self::assertSame(1, (int)$task['is_active']);

        $config = $connection->select(
            ['*'],
            'tx_nrllm_configuration',
            ['identifier' => 'wizard-made-config'],
        )->fetchAssociative();
        self::assertIsArray($config);
        // Out-of-range numerics are clamped, not rejected.
        self::assertIsNumeric($config['temperature']);
        self::assertSame(2.0, (float)$config['temperature']);
        self::assertSame(128000, (int)$config['max_tokens']);
        self::assertIsNumeric($config['top_p']);
        self::assertSame(1.0, (float)$config['top_p']);
        // The created task points at the created configuration.
        self::assertSame((int)$config['uid'], (int)$task['configuration_uid']);
    }

    #[Test]
    public function wizardCreateActionWithNonArrayBodyRedirectsToForm(): void
    {
        $this->setUpFixturesAndUser();
        $controller = $this->createController($this->createBackendRequest(null));

        $response = $controller->wizardCreateAction();

        $this->assertRedirectsToWizardForm($response);
    }

    private function setUpFixturesAndUser(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('BeUsers.csv');
        $backendUser = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function createController(ExtbaseRequest $request): TaskWizardController
    {
        $controller = new TaskWizardController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(TaskRepository::class),
            $this->getService(LlmConfigurationRepository::class),
            $this->getService(ModelRepository::class),
            $this->getService(WizardGeneratorService::class),
            $this->getService(PersistenceManagerInterface::class),
            $this->getService(FlashMessageService::class),
            $this->getService(PageRenderer::class),
            $this->getService(BackendUriBuilder::class),
            new NullLogger(),
        );
        $this->setPrivateProperty($controller, 'request', $request);

        // The validation redirects go through Extbase's UriBuilder.
        $uriBuilder = $this->getService(ExtbaseUriBuilder::class);
        $uriBuilder->setRequest($request);
        $this->setPrivateProperty($controller, 'uriBuilder', $uriBuilder);

        return $controller;
    }

    private function assertRedirectsToWizardForm(ResponseInterface $response): void
    {
        self::assertInstanceOf(RedirectResponse::class, $response);

        // The guard paths must leave the user an explanation: exactly the
        // enqueued flash message. (The redirect target itself is built by
        // Extbase's UriBuilder, which resolves to the module URL only under
        // a fully routed backend request — not in this harness.)
        $messages = $this->getService(FlashMessageService::class)
            ->getMessageQueueByIdentifier()
            ->getAllMessagesAndFlush();
        self::assertNotEmpty($messages);
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createBackendRequest(?array $parsedBody = []): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\TaskWizard');
        $extbaseParameters->setControllerActionName('wizardForm');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/tasks', [
                'packageName' => 'netresearch/nr-llm',
                '_identifier' => 'nrllm_tasks',
            ]))
            ->withAttribute('extbase', $extbaseParameters);
        if ($parsedBody !== null) {
            $serverRequest = $serverRequest->withParsedBody($parsedBody);
        }
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
