<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\AnalyticsController;
use Netresearch\NrLlm\Service\Analytics\FallbackRescueReport;
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
    public function indexActionRendersTheFallbackRescueListFromRealTelemetryRows(): void
    {
        // Two rows a `fallback_attempts > 0` query cannot tell apart: one a
        // sibling answered, one where the chain was exhausted. Rendering with
        // real rows (not the empty state) is what exercises the table markup.
        $this->insertTelemetryRow('rescued-corr', 'primary-config', 'sibling-config');
        $this->insertTelemetryRow('exhausted-corr', 'primary-config', 'primary-config');

        $body = (string)$this->dispatchIndex([])->getBody();

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

        $body = (string)$this->dispatchIndex([])->getBody();

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

        $body = (string)$this->dispatchIndex([])->getBody();

        self::assertStringContainsString('No data in the window', $body);
        self::assertStringNotContainsString('<td>0.00</td>', $body, 'An unused provider must never be scored 0.');
        self::assertStringNotContainsString('<td>0</td>', $body, 'An unused provider must show no sample count either.');
    }

    #[Test]
    public function indexActionSaysTheFallbackReorderSwitchIsOff(): void
    {
        // Default: health.reorderFallback is off, so the scores decide nothing.
        $body = (string)$this->dispatchIndex([])->getBody();

        self::assertStringContainsString('Health-aware fallback reorder is OFF', $body);
    }

    #[Test]
    public function indexActionSaysTheFallbackReorderSwitchIsOn(): void
    {
        // The instance-level switch ExtensionConfiguration reads. Each
        // functional test re-bootstraps TYPO3_CONF_VARS, so this does not leak.
        $this->storeNrLlmConfig(['health' => ['reorderFallback' => '1']]);

        $body = (string)$this->dispatchIndex([])->getBody();

        self::assertStringContainsString('Health-aware fallback reorder is ON', $body);
        self::assertStringNotContainsString('Health-aware fallback reorder is OFF', $body);
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
            $this->getService(FallbackRescueReport::class),
            $this->getService(ProviderHealthReport::class),
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
