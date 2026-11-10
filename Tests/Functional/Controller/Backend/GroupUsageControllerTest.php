<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Controller\Backend\AnalyticsController;
use Netresearch\NrLlm\Controller\Backend\GroupUsageController;
use Netresearch\NrLlm\Service\Analytics\FallbackRescueReport;
use Netresearch\NrLlm\Service\Analytics\MonthlyBillingReportService;
use Netresearch\NrLlm\Service\Analytics\ProviderHealthReport;
use Netresearch\NrLlm\Service\Analytics\UsageAudienceResolver;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
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
 * The group usage view (ADR-277) shows a head editor the AI usage of the
 * members of his assigned groups and of nobody else — not in a table, a
 * total, a chart or the embedded chart JSON.
 *
 * Every user who must stay invisible has a username, provider, model,
 * service, calling extension and cost of his own, so a leak through any
 * route names its source. Fixture: GroupUsageVisibility.csv.
 */
#[CoversClass(GroupUsageController::class)]
final class GroupUsageControllerTest extends AbstractFunctionalTestCase
{
    /** Members of News team (51), directly or through News seniors (55). */
    private const VISIBLE = [
        71 => ['username' => 'news-alice', 'cost' => 1.25],
        73 => ['username' => 'senior-carol', 'cost' => 2.5],
    ];

    /**
     * Everyone the head editor (70) must not see, each with a cost no
     * visible sum can produce.
     */
    private const INVISIBLE = [
        72 => ['username' => 'sports-bob', 'cost' => 7777.77],
        74 => ['username' => 'base-dave', 'cost' => 6666.66],
        75 => ['username' => 'disabled-erin', 'cost' => 5555.55],
        76 => ['username' => 'hiddengroup-hank', 'cost' => 4444.44],
        1  => ['username' => 'admin-in-news', 'cost' => 3333.33],
        0  => ['username' => 'system-rows', 'cost' => 2222.22],
        81 => ['username' => '_cli_scheduler', 'cost' => 1111.11],
        70 => ['username' => 'head-hugo', 'cost' => 8888.88],
        77 => ['username' => 'nogrant-nora', 'cost' => 9999.99],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('GroupUsageVisibility.csv');

        foreach (self::VISIBLE + self::INVISIBLE as $uid => $user) {
            $this->insertUsageRow($uid, $user['username'], $user['cost']);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aHolderSeesTheMembersOfHisAssignedGroup(): void
    {
        $response = $this->dispatchAs(70);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        foreach (self::VISIBLE as $user) {
            self::assertStringContainsString($user['username'], $body);
            self::assertStringContainsString('model-' . $user['username'], $body);
        }

        self::assertStringContainsString('News team', $body, 'The view names the groups it covers.');
    }

    #[Test]
    public function aHolderSeesNoTraceOfAnyoneElseAnywhereInThePage(): void
    {
        $body = (string) $this->dispatchAs(70)->getBody();

        foreach (self::INVISIBLE as $uid => $user) {
            $name = $user['username'];
            foreach ([$name, 'model-' . $name, 'prov-' . $name, 'svc-' . $name, 'ext_' . $name] as $needle) {
                self::assertStringNotContainsString($needle, $body, sprintf('user %d leaks as "%s"', $uid, $needle));
            }

            $cost = $user['cost'];
            self::assertStringNotContainsString(number_format($cost, 2), $body, sprintf('user %d leaks his cost', $uid));
            self::assertStringNotContainsString((string) $cost, $body, sprintf('user %d leaks his raw cost', $uid));
        }
    }

    #[Test]
    public function theTotalsAreTheSumOfTheVisibleUsersAlone(): void
    {
        $body = (string) $this->dispatchAs(70)->getBody();

        // 1.25 + 2.50; any other user would add at least 1,111.11.
        self::assertStringContainsString('<div class="nrllm-kpi-value">~$3.75</div>', $body);
    }

    #[Test]
    public function theEmbeddedChartDataHoldsOnlyTheVisibleUsersRows(): void
    {
        $chart = $this->chartData((string) $this->dispatchAs(70)->getBody());

        $expected = ['news-alice', 'senior-carol'];
        foreach (['byProvider' => 'prov-', 'byModel' => 'model-', 'byService' => 'svc-', 'bySource' => 'ext_'] as $key => $prefix) {
            $labels = array_column($chart[$key], 'label');
            sort($labels);
            self::assertSame(array_map(static fn (string $n): string => $prefix . $n, $expected), $labels, $key);
            self::assertEqualsWithDelta(3.75, array_sum(array_column($chart[$key], 'cost')), 0.000001, $key);
        }

        self::assertEqualsWithDelta(3.75, array_sum(array_column($chart['trend'], 'cost')), 0.000001, 'trend');
        self::assertSame(2, array_sum(array_column($chart['trend'], 'requests')), 'trend');
    }

    #[Test]
    public function theViewCarriesNoAdministratorPart(): void
    {
        $body = (string) $this->dispatchAs(70)->getBody();

        self::assertStringNotContainsString('Fallback rescues', $body);
        self::assertStringNotContainsString('Provider health and circuits', $body);
        self::assertStringNotContainsString('Monthly billing report', $body);
        self::assertStringNotContainsString('exportBilling', $body);
        self::assertStringNotContainsString('action=billing', $body);
        self::assertStringNotContainsString('This module is admin-only', $body);
    }

    #[Test]
    public function aHolderWithoutAnAssignmentSeesNoUsage(): void
    {
        $response = $this->dispatchAs(78);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('No backend groups are assigned', $body);
        $this->assertNoUsageAtAll($body);
    }

    #[Test]
    public function anAssignedGroupWithoutMembersShowsNoUsage(): void
    {
        $response = $this->dispatchAs(80);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Empty team', $body);
        self::assertStringContainsString('<div class="nrllm-kpi-value">~$0.00</div>', $body);
        $this->assertNoUsageAtAll($body);
    }

    #[Test]
    public function aUserWithoutTheGrantGetsNothing(): void
    {
        // 77 holds no grant; 83 holds an assignment but no grant.
        foreach ([77, 83] as $uid) {
            $response = $this->dispatchAs($uid);

            self::assertSame(403, $response->getStatusCode(), (string) $uid);
            $this->assertNoUsageAtAll((string) $response->getBody());
        }
    }

    #[Test]
    public function anAdministratorSeesEveryone(): void
    {
        $body = (string) $this->dispatchAs(1)->getBody();

        foreach (self::VISIBLE + self::INVISIBLE as $uid => $user) {
            if ($uid === 0) {
                continue; // listed as "system", see below
            }

            self::assertStringContainsString($user['username'], $body);
        }

        self::assertStringContainsString('model-system-rows', $body);
    }

    #[Test]
    public function aHolderGetsNeitherTheAnalyticsModuleNorTheBillingReportNorItsExport(): void
    {
        foreach (['index', 'billing', 'exportBilling'] as $action) {
            $response = $this->dispatchAnalyticsAs(70, $action);

            self::assertSame(403, $response->getStatusCode(), $action);
            self::assertStringNotContainsString('text/csv', $response->getHeaderLine('Content-Type'), $action);
            $this->assertNoUsageAtAll((string) $response->getBody());
        }
    }

    #[Test]
    public function noAjaxRouteServesUsageData(): void
    {
        // The routes as TYPO3 registered them, from every extension, not one
        // configuration file.
        $ajaxRoutes = 0;
        foreach ($this->getService(Router::class)->getRoutes() as $route) {
            if ($route->getOption('ajax') !== true) {
                continue;
            }

            ++$ajaxRoutes;
            $target = $route->getOption('target');
            $target = is_string($target) ? $target : '';
            self::assertStringStartsNotWith(AnalyticsController::class, $target, $route->getPath());
            self::assertStringStartsNotWith(GroupUsageController::class, $target, $route->getPath());
        }

        self::assertGreaterThan(0, $ajaxRoutes, 'The route registry must have been read.');
    }

    private function assertNoUsageAtAll(string $body): void
    {
        foreach (self::VISIBLE + self::INVISIBLE as $uid => $user) {
            self::assertStringNotContainsString('model-' . $user['username'], $body, (string) $uid);
            self::assertStringNotContainsString(number_format($user['cost'], 2), $body, (string) $uid);
        }
    }

    /**
     * @return array<string, list<array<mixed>>>
     */
    private function chartData(string $body): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" id="nrllm-analytics-data">(.*?)</script>#s', $body, $match));
        $data = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        $out = [];
        foreach (['trend', 'byProvider', 'byModel', 'byService', 'bySource'] as $key) {
            self::assertIsArray($data[$key] ?? null, $key);
            $rows = [];
            foreach ($data[$key] as $row) {
                self::assertIsArray($row);
                $rows[] = $row;
            }

            $out[$key] = $rows;
        }

        return $out;
    }

    private function dispatchAs(int $backendUserUid): ResponseInterface
    {
        $this->logIn($backendUserUid);

        $controller = new GroupUsageController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(UsageAnalyticsServiceInterface::class),
            $this->getService(UsageAudienceResolver::class),
            $this->getService(BackendUriBuilder::class),
            $this->getService(PageRenderer::class),
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest('Backend\GroupUsage', 'index', '/module/nrllm/group-usage'));

        return $controller->indexAction();
    }

    private function dispatchAnalyticsAs(int $backendUserUid, string $action): ResponseInterface
    {
        $this->logIn($backendUserUid);

        $controller = new AnalyticsController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(UsageAnalyticsServiceInterface::class),
            $this->getService(FallbackRescueReport::class),
            $this->getService(ProviderHealthReport::class),
            $this->getService(BackendUriBuilder::class),
            $this->getService(PageRenderer::class),
            $this->getService(MonthlyBillingReportService::class),
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest('Backend\Analytics', $action, '/module/nrllm/analytics'));

        return match ($action) {
            'billing'       => $controller->billingAction(),
            'exportBilling' => $controller->exportBillingAction(),
            default         => $controller->indexAction(),
        };
    }

    private function logIn(int $backendUserUid): void
    {
        $backendUser     = $this->setUpBackendUser($backendUserUid);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function createBackendRequest(string $controllerName, string $action, string $path): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName($controllerName);
        $extbaseParameters->setControllerActionName($action);
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route($path, ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }

    private function insertUsageRow(int $beUser, string $marker, float $cost): void
    {
        $ts = (new DateTimeImmutable('today'))->getTimestamp();
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_service_usage')
            ->insert('tx_nrllm_service_usage', [
                'pid'               => 0,
                'service_type'      => 'svc-' . $marker,
                'service_provider'  => 'prov-' . $marker,
                'model_id'          => 'model-' . $marker,
                'source_extension'  => 'ext_' . $marker,
                'be_user'           => $beUser,
                'request_count'     => 1,
                'tokens_used'       => 100,
                'prompt_tokens'     => 60,
                'completion_tokens' => 40,
                'estimated_cost'    => $cost,
                'request_date'      => $ts,
                'tstamp'            => $ts,
                'crdate'            => $ts,
            ]);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        (new ReflectionClass($object))->getProperty($property)->setValue($object, $value);
    }
}
