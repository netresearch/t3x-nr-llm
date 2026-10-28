<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Controller\Backend\AnalyticsController;
use Netresearch\NrLlm\Service\Analytics\BillingMonth;
use Netresearch\NrLlm\Service\Analytics\FallbackRescueReport;
use Netresearch\NrLlm\Service\Analytics\MonthlyBillingReportService;
use Netresearch\NrLlm\Service\Analytics\ProviderHealthReport;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
#[CoversClass(MonthlyBillingReportService::class)]
final class AnalyticsControllerTest extends AbstractFunctionalTestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalStoredConfiguration = null;

    private bool $backendUsersImported = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStoredConfiguration = $this->getService(ConfigurationManager::class)->getLocalConfiguration();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->originalStoredConfiguration !== null) {
                self::assertTrue(
                    $this
                        ->getService(ConfigurationManager::class)
                        ->writeLocalConfiguration($this->originalStoredConfiguration),
                );
            }
        } finally {
            unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
            parent::tearDown();
        }
    }

    #[Test]
    public function indexActionRendersDashboardWithChartData(): void
    {
        $response = $this->dispatchIndex([]);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

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
    public function indexActionRendersTheFallbackRescueListFromRealTelemetryRows(): void
    {
        // Two rows a `fallback_attempts > 0` query cannot tell apart: one a
        // sibling answered, one where the chain was exhausted. Rendering with
        // real rows (not the empty state) is what exercises the table markup.
        $this->insertTelemetryRow('rescued-corr', 'primary-config', 'sibling-config');
        $this->insertTelemetryRow('exhausted-corr', 'primary-config', 'primary-config');

        $body = (string) $this->dispatchIndex([])->getBody();

        self::assertStringContainsString('sibling-config', $body, 'The rescue must be listed.');
        self::assertSame(
            1,
            substr_count($body, 'primary-config'),
            'Only the rescued run appears — the exhausted one names no other configuration.',
        );
    }

    #[Test]
    public function indexActionRendersProviderHealthScoresWithTheirSampleCountAndWindow(): void
    {
        // Two active providers (ollama, openai); only openai is exercised.
        $this->importFixture('Providers.csv');
        $this->insertHealthRow('openai', true, 100);
        $this->insertHealthRow('openai', true, 300);
        $this->insertHealthRow('openai', false, 200);

        $body = (string) $this->dispatchIndex([])->getBody();

        // The window the scores were taken over is named, not left implicit.
        self::assertStringContainsString('last 900 seconds', $body);
        // 0.8 * (2/3) + 0.2 * (1 - 200/5000) = 0.7253
        self::assertStringContainsString('<td>0.73</td>', $body, 'The score must be rendered.');
        self::assertStringContainsString('<td>3</td>', $body, 'The sample count must be next to the score.');
        self::assertStringContainsString('<td>0.67</td>', $body, 'The success rate must be rendered.');
        self::assertStringContainsString('<td>200 ms</td>', $body, 'The mean latency must be rendered.');
    }

    #[Test]
    public function indexActionShowsNoDataForAProviderWithoutTelemetryInsteadOfAZero(): void
    {
        // ollama is configured and active but was never called; openai has one
        // sample. A 0 for ollama would read as "broken" — it was simply idle.
        $this->importFixture('Providers.csv');
        $this->insertHealthRow('openai', true, 100);

        $body = (string) $this->dispatchIndex([])->getBody();

        self::assertStringContainsString('No data in the window', $body);
        self::assertStringNotContainsString('<td>0.00</td>', $body, 'An unused provider must never be scored 0.');
        self::assertStringNotContainsString('<td>0</td>', $body, 'An unused provider must show no sample count either.');
    }

    #[Test]
    public function indexActionShowsNoLatencyForAProviderThatNeverServedARunItself(): void
    {
        // Two runs in the window, both rescued by a fallback: openai has a real
        // score and success rate but nothing that measures its own latency.
        // "0 ms" here reads as sub-millisecond fast — the same zero-for-no-data
        // confusion the score column avoids.
        $this->insertRescuedHealthRow('openai', 153);
        $this->insertRescuedHealthRow('openai', 247);

        $body = (string) $this->dispatchIndex([])->getBody();

        self::assertStringContainsString('<td>0.20</td>', $body, 'The score is a real statement and stays.');
        self::assertStringNotContainsString('<td>0 ms</td>', $body, 'An unmeasured latency must never render as a duration.');
        self::assertStringContainsString('never answered on its own', $body);
    }

    #[Test]
    public function indexActionSaysTheFallbackReorderSwitchIsOff(): void
    {
        // Default: health.reorderFallback is off, so the scores decide nothing.
        $body = (string) $this->dispatchIndex([])->getBody();

        self::assertStringContainsString('Health-aware fallback reorder is OFF', $body);
    }

    #[Test]
    public function indexActionSaysTheFallbackReorderSwitchIsOn(): void
    {
        // Core may persist missing extension defaults during rendering.
        // tearDown restores the instance configuration before the next test bootstraps.
        $this->storeNrLlmConfig(['health' => ['reorderFallback' => '1']]);

        $body = (string) $this->dispatchIndex([])->getBody();

        self::assertStringContainsString('Health-aware fallback reorder is ON', $body);
        self::assertStringNotContainsString('Health-aware fallback reorder is OFF', $body);
    }

    #[Test]
    public function indexActionNormalizesUnknownRangeParameter(): void
    {
        $response = $this->dispatchIndex(['range' => 'bogus-range']);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function indexActionLinksToTheBillingReportOfThePreviousMonth(): void
    {
        $body = (string) $this->dispatchIndex([])->getBody();

        self::assertStringContainsString('Monthly billing report', $body);
        self::assertStringContainsString('action=billing', $body);
        self::assertStringContainsString('month=' . BillingMonth::previous(new DateTimeImmutable())->key(), $body);
    }

    #[Test]
    public function billingActionRendersTheMonthWithCaptionedTablesAndATokenisedExportLink(): void
    {
        $month = BillingMonth::previous(new DateTimeImmutable());
        $this->insertUsageRow($month->start->modify('+2 days'), 1, 12.5, 'Admin <script>');
        $this->storeNrLlmConfig(['billing' => ['markupPercent' => '20']]);

        $response = $this->dispatch('billing', ['month' => $month->key()]);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Monthly billing report', $body);
        // Cost, active editors, results by kind; no entitled editor exists, so
        // that list is a sentence, not an empty table.
        self::assertSame(3, substr_count($body, '<caption>'), 'Every table names itself.');
        self::assertStringContainsString('data-nrllm-billing="cost">~$12.50<', $body);
        self::assertStringContainsString('data-nrllm-billing="markup-percent">20 %<', $body);
        self::assertStringContainsString('data-nrllm-billing="markup">~$2.50<', $body);
        self::assertStringContainsString('data-nrllm-billing="total"><strong>~$15.00</strong><', $body);
        self::assertStringContainsString('Active editors: 1', $body);
        self::assertStringContainsString('Entitled editors: 0', $body);
        self::assertStringContainsString('As of today, ' . (new DateTimeImmutable())->format('Y-m-d'), $body);
        self::assertStringContainsString('Results by kind', $body);
        // A calling extension is what the caller claims: escaped, never markup.
        self::assertStringNotContainsString('Admin <script>', $body);
        self::assertStringContainsString('Admin &lt;script&gt;', $body);
        // The export is a real link with a text label, carrying the route token.
        self::assertMatchesRegularExpression('/<a href="(?=[^"]*token=[0-9a-f]{20,})(?=[^"]*action=exportBilling)[^"]*"[^>]*>\s*Download CSV/', $body);
        self::assertStringContainsString('nr-llm-billing-' . $month->key() . '.csv', $body);
    }

    #[Test]
    public function billingActionShowsCentsThatAddUp(): void
    {
        // 0.135 at 100 %: cost 0.14 and total 0.27 rounded on their own, so the
        // markup line must read 0.13. The markup rounded on its own would read
        // 0.14 (the formatter rounds half up).
        $month = BillingMonth::previous(new DateTimeImmutable());
        $this->insertUsageRow($month->start->modify('+2 days'), 1, 0.135, 'my_ext');
        $this->storeNrLlmConfig(['billing' => ['markupPercent' => '100']]);

        $body = (string) $this->dispatch('billing', ['month' => $month->key()])->getBody();

        self::assertStringContainsString('data-nrllm-billing="cost">~$0.14<', $body);
        self::assertStringContainsString('data-nrllm-billing="markup">~$0.13<', $body);
        self::assertStringContainsString('data-nrllm-billing="total"><strong>~$0.27</strong><', $body);
    }

    #[Test]
    public function exportBillingActionDownloadsTheMonthAsCsv(): void
    {
        $month = BillingMonth::previous(new DateTimeImmutable());
        $this->insertUsageRow($month->start, 1, 0.5, 'my_ext');
        $this->insertUsageRow($month->lastSecond->setTime(0, 0), 0, 0.25, '');
        // The month after: not in the file.
        $this->insertUsageRow($month->lastSecond->modify('+1 second'), 1, 100.0, 'my_ext');

        $response = $this->dispatch('exportBilling', ['month' => $month->key()]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="nr-llm-billing-' . $month->key() . '.csv"',
            $response->getHeaderLine('Content-Disposition'),
        );
        $lines = explode("\r\n", trim((string) $response->getBody()));
        self::assertStringStartsWith('section,month,be_user_uid,username,', $lines[0]);
        self::assertStringStartsWith('total,' . $month->key() . ',,,,,,,,,2,200,0.750000,0,0.000000,0.750000,', $lines[1]);
    }

    #[Test]
    public function aNonAdministratorGetsNeitherTheReportNorTheExport(): void
    {
        $this->insertUsageRow(BillingMonth::previous(new DateTimeImmutable())->start, 1, 0.5, 'my_ext');

        foreach (['billing', 'exportBilling'] as $action) {
            $response = $this->dispatch($action, [], 2); // uid 2 is an editor (admin=0)

            self::assertSame(403, $response->getStatusCode(), $action);
            self::assertStringNotContainsString('0.500000', (string) $response->getBody(), $action);
            self::assertStringNotContainsString('text/csv', $response->getHeaderLine('Content-Type'), $action);
        }
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function dispatchIndex(array $queryParams): ResponseInterface
    {
        return $this->dispatch('index', $queryParams);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function dispatch(string $action, array $queryParams, int $backendUserUid = 1): ResponseInterface
    {
        if (!$this->backendUsersImported) {
            $this->importFixture('BeUsers.csv');
            $this->backendUsersImported = true;
        }

        $backendUser     = $this->setUpBackendUser($backendUserUid); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $controller = new AnalyticsController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(UsageAnalyticsServiceInterface::class),
            $this->getService(FallbackRescueReport::class),
            $this->getService(ProviderHealthReport::class),
            $this->getService(BackendUriBuilder::class),
            $this->getService(PageRenderer::class),
            $this->getService(MonthlyBillingReportService::class),
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest($queryParams, $action));

        return match ($action) {
            'billing'       => $controller->billingAction(),
            'exportBilling' => $controller->exportBillingAction(),
            default         => $controller->indexAction(),
        };
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function createBackendRequest(array $queryParams, string $action = 'index'): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\Analytics');
        $extbaseParameters->setControllerActionName($action);
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/analytics', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }

    private function insertTelemetryRow(string $correlationId, string $requested, string $served): void
    {
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_telemetry')
            ->insert('tx_nrllm_telemetry', [
                'pid'                             => 0,
                'correlation_id'                  => $correlationId,
                'operation'                       => 'chat',
                'provider'                        => 'openai',
                'model'                           => 'gpt-5',
                'configuration_identifier'        => $requested,
                'served_configuration_identifier' => $served,
                'served_provider'                 => 'ollama',
                'served_model'                    => 'llama3.3:70b',
                'be_user'                         => 1,
                'success'                         => 1,
                'error_class'                     => '',
                'latency_ms'                      => 42,
                'cache_hit'                       => 0,
                'fallback_attempts'               => 1,
                'crdate'                          => time(),
            ]);
    }

    private function insertUsageRow(DateTimeImmutable $day, int $beUser, float $cost, string $sourceExtension): void
    {
        $ts = $day->getTimestamp();
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_service_usage')
            ->insert('tx_nrllm_service_usage', [
                'pid'               => 0,
                'service_type'      => 'chat',
                'service_provider'  => 'openai',
                'model_id'          => 'gpt-5',
                'source_extension'  => $sourceExtension,
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

    /**
     * A self-served run (no fallback hop), which is what the health scores
     * count — see ProviderHealthRepository.
     */
    private function insertHealthRow(string $provider, bool $success, int $latencyMs): void
    {
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_telemetry')
            ->insert('tx_nrllm_telemetry', [
                'pid'                      => 0,
                'correlation_id'           => 'health-' . uniqid('', true),
                'operation'                => 'chat',
                'provider'                 => $provider,
                'model'                    => '',
                'configuration_identifier' => 'primary',
                'be_user'                  => 0,
                'success'                  => $success ? 1 : 0,
                'error_class'              => '',
                'latency_ms'               => $latencyMs,
                'cache_hit'                => 0,
                'fallback_attempts'        => 0,
                'crdate'                   => time(),
            ]);
    }

    /**
     * A run the requested provider lost and a fallback rescued: a sample and a
     * primary failure, but no measurement of the requested provider's latency.
     */
    private function insertRescuedHealthRow(string $provider, int $latencyMs): void
    {
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_telemetry')
            ->insert('tx_nrllm_telemetry', [
                'pid'                      => 0,
                'correlation_id'           => 'health-' . uniqid('', true),
                'operation'                => 'chat',
                'provider'                 => $provider,
                'model'                    => '',
                'configuration_identifier' => 'primary',
                'be_user'                  => 0,
                'success'                  => 1,
                'error_class'              => '',
                'latency_ms'               => $latencyMs,
                'cache_hit'                => 0,
                'fallback_attempts'        => 1,
                'crdate'                   => time(),
            ]);
    }

    /**
     * Write the raw stored nr_llm extension configuration, narrowing the untyped
     * $GLOBALS shape step by step so the writes stay PHPStan-clean.
     *
     * @param array<string, mixed> $nrLlm
     */
    private function storeNrLlmConfig(array $nrLlm): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }

        $extensions = $confVars['EXTENSIONS'] ?? [];
        if (!is_array($extensions)) {
            $extensions = [];
        }

        $extensions['nr_llm']       = $nrLlm;
        $confVars['EXTENSIONS']     = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $reflection->getProperty($property)->setValue($object, $value);
    }
}
