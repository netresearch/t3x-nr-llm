<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\AnalyticsController;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Renders the usage analytics dashboard through the real ModuleTemplate
 * stack against an empty usage table: the chart containers and the
 * embedded chart JSON must render, and an unknown ?range= value must be
 * normalized instead of failing.
 */
#[CoversClass(AnalyticsController::class)]
final class AnalyticsControllerTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function indexActionRendersDashboardWithChartData(): void
    {
        $response = $this->dispatchIndex([]);

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        self::assertStringContainsString('id="nrllm-analytics-data"', $body);
        self::assertStringContainsString('id="nrllm-trend-chart"', $body);
        self::assertStringContainsString('id="nrllm-provider-chart"', $body);
        // The embedded JSON payload carries all four datasets.
        self::assertStringContainsString('"trend"', $body);
        self::assertStringContainsString('"byProvider"', $body);
        self::assertStringContainsString('"byModel"', $body);
        self::assertStringContainsString('"byService"', $body);
    }

    #[Test]
    public function indexActionNormalizesUnknownRangeParameter(): void
    {
        $response = $this->dispatchIndex(['range' => 'bogus-range']);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function dispatchIndex(array $queryParams): ResponseInterface
    {
        $this->importFixture('BeUsers.csv');
        $backendUser = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $controller = new AnalyticsController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(UsageAnalyticsServiceInterface::class),
            $this->getService(BackendUriBuilder::class),
            $this->getService(PageRenderer::class),
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest($queryParams));

        return $controller->indexAction();
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function createBackendRequest(array $queryParams): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\Analytics');
        $extbaseParameters->setControllerActionName('index');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/analytics', ['packageName' => 'netresearch/nr-llm']))
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
