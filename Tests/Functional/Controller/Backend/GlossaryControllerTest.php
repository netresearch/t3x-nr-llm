<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\FormEngineUrlBuilder;
use Netresearch\NrLlm\Controller\Backend\GlossaryController;
use Netresearch\NrLlm\Domain\Repository\GlossaryRepository;
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
 * Renders the translation glossary list through the real ModuleTemplate stack
 * (ADR-208): the fixture glossaries surface with their site, language pair and
 * effective term count, hidden ones included, with FormEngine edit and new
 * links.
 */
#[CoversClass(GlossaryController::class)]
final class GlossaryControllerTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function listActionRendersTheGlossaries(): void
    {
        $this->importFixture('Glossaries.csv');
        $this->importFixture('BeUsers.csv');
        $backendUser = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $controller = new GlossaryController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(IconFactory::class),
            $this->getService(GlossaryRepository::class),
            $this->getService(FormEngineUrlBuilder::class),
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $response = $controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        self::assertStringContainsString('Shop DE-EN', $body);
        self::assertStringContainsString('Other site DE-EN', $body);
        // The module lists hidden records so they can be switched back on;
        // the translation path is what ignores them.
        self::assertStringContainsString('Hidden DE-FR', $body);
        // Deleted records do not surface.
        self::assertStringNotContainsString('Deleted EN-DE', $body);
        // Record 1 holds two usable pairs; the column shows what takes effect.
        self::assertMatchesRegularExpression(
            '#<strong>Shop DE-EN</strong></td>\s*<td><code>main</code></td>\s*<td><code>de</code> → <code>en</code></td>\s*<td>2</td>#',
            $body,
        );
        // Within one site and pair the list puts the record the translation
        // path uses first: the lowest uid, not the name that sorts first.
        $applied = strpos($body, '<strong>Shop DE-EN</strong>');
        $shadowed = strpos($body, '<strong>A newer shop DE-EN</strong>');
        self::assertIsInt($applied);
        self::assertIsInt($shadowed);
        self::assertLessThan($shadowed, $applied);
        // FormEngine deep links (the record/edit backend route) for editing
        // and creating records.
        self::assertStringContainsString('record/edit', $body);
        self::assertStringContainsString('tx_nrllm_glossary', $body);
    }

    private function createBackendRequest(): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\Glossary');
        $extbaseParameters->setControllerActionName('list');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/glossaries', ['packageName' => 'netresearch/nr-llm']))
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
