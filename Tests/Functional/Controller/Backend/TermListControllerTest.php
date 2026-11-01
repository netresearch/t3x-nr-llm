<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\FormEngineUrlBuilder;
use Netresearch\NrLlm\Controller\Backend\TermListController;
use Netresearch\NrLlm\Domain\Repository\TermListRepository;
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
 * Renders the term list module through the real ModuleTemplate stack (ADR-238).
 */
#[CoversClass(TermListController::class)]
final class TermListControllerTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function listActionRendersTheLists(): void
    {
        $this->importFixture('TermLists.csv');
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $controller = new TermListController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(IconFactory::class),
            $this->getService(TermListRepository::class),
            $this->getService(FormEngineUrlBuilder::class),
        );
        (new ReflectionClass($controller))->getProperty('request')->setValue($controller, $this->createBackendRequest());

        $body = (string) $controller->listAction()->getBody();

        self::assertStringContainsString('Ausgeblendet', $body);
        self::assertStringNotContainsString('Geloescht', $body);
        self::assertMatchesRegularExpression(
            '#<strong>Marke</strong></td>\s*<td>All sites</td>\s*<td>All languages</td>\s*<td>2</td>\s*<td>2</td>\s*<td>0</td>#',
            $body,
        );
        self::assertMatchesRegularExpression(
            '#<strong>Recht</strong></td>\s*<td><code class="text-break">shop</code></td>\s*<td><code class="text-break">de</code></td>\s*<td>1</td>\s*<td>0</td>\s*<td>1</td>#',
            $body,
        );
        self::assertStringContainsString('record/edit', $body);
        self::assertStringContainsString('tx_nrllm_term_list', $body);
    }

    private function createBackendRequest(): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\TermList');
        $extbaseParameters->setControllerActionName('list');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/term-lists', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }
}
