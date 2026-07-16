<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\FormEngineUrlBuilder;
use Netresearch\NrLlm\Controller\Backend\PromptSnippetController;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Renders the prompt snippet library list through the real ModuleTemplate
 * stack: the active fixture snippets must surface with FormEngine edit
 * URLs and the new-record doc-header button.
 */
#[CoversClass(PromptSnippetController::class)]
final class PromptSnippetControllerTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function listActionRendersSnippetLibrary(): void
    {
        $this->importFixture('PromptSnippets.csv');
        $this->importFixture('BeUsers.csv');
        $backendUser = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $controller = new PromptSnippetController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(IconFactory::class),
            $this->getService(PromptSnippetRepository::class),
            $this->getService(FormEngineUrlBuilder::class),
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $response = $controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        // Active fixture snippets surface by name.
        self::assertStringContainsString('Casual tone', $body);
        self::assertStringContainsString('Formal tone', $body);
        // FormEngine deep links (the record/edit backend route) for editing
        // and creating records.
        self::assertStringContainsString('record/edit', $body);
        self::assertStringContainsString('tx_nrllm_promptsnippet', $body);
    }

    private function createBackendRequest(): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\PromptSnippet');
        $extbaseParameters->setControllerActionName('list');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/snippets', ['packageName' => 'netresearch/nr-llm']))
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
