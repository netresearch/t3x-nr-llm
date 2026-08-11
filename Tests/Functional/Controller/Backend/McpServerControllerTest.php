<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\McpServerController;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * What the MCP import route does for an administrator who names nothing usable.
 *
 * The non-admin denial lives in {@see BackendAjaxAdminGuardTest} with the other
 * guarded routes. What is left here is the case above that gate: the import
 * reaches an external party, so it must happen only for a server the caller
 * actually named, and never for "whatever the first row is".
 */
#[CoversClass(McpServerController::class)]
final class McpServerControllerTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        // uid 1 is an admin (admin=1) — past the guard, into the action itself.
        $this->setUpBackendUser(1);

        // Resolving an Extbase ActionController from the container triggers
        // injectConfigurationManager(), which reads settings from the current
        // request.
        $GLOBALS['TYPO3_REQUEST'] = (new Typo3ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        // A real server at uid 1, which is what the coercion cases below would
        // land on if a malformed value were cast instead of validated. Without
        // it those cases answer 404 for the wrong reason and prove nothing.
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('tx_nrllm_mcp_server')->insert('tx_nrllm_mcp_server', [
            'uid'              => 1,
            'pid'              => 0,
            'identifier'       => 'srv',
            'name'             => 'A server',
            'description'      => '',
            'url'              => 'https://mcp.example.com/rpc',
            'auth_credential'  => '',
            'auth_placement'   => 'bearer',
            'auth_header_name' => '',
            'data_class'       => 'publicContent',
            'enabled'          => 1,
            'import_status'    => 'never_imported',
            'import_error'     => '',
            'last_imported'    => 0,
            'tool_count'       => 0,
            'tstamp'           => 0,
            'crdate'           => 0,
            'deleted'          => 0,
            'hidden'           => 0,
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);

        parent::tearDown();
    }

    private function controller(): McpServerController
    {
        $controller = $this->get(McpServerController::class);
        self::assertInstanceOf(McpServerController::class, $controller);

        return $controller;
    }

    #[Test]
    public function answersNotFoundForAServerThatDoesNotExist(): void
    {
        $response = $this->controller()->importAction(
            (new ServerRequest('POST', '/ajax/nrllm/mcp/import'))->withParsedBody(['server' => 4711]),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Unknown MCP server', (string)$response->getBody());
    }

    #[Test]
    public function answersNotFoundWhenNoServerWasNamed(): void
    {
        $response = $this->controller()->importAction(
            (new ServerRequest('POST', '/ajax/nrllm/mcp/import'))->withParsedBody([]),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableServerValues(): array
    {
        return [
            'not a number'       => ['first'],
            // The ones a cast would silently accept: is_numeric() says yes to
            // both, and casting turns them into a uid nobody named — 1 and
            // 1000 respectively. The import reaches an external server, so it
            // must never be one rounding rule away from the wrong row.
            'decimal'            => ['1.9'],
            'exponent'           => ['1e3'],
            'empty'              => [''],
            'an array'           => [['1']],
            // Deliberately absent: ' 1'. FILTER_VALIDATE_INT trims surrounding
            // whitespace and answers 1, which is right — a padded integer means
            // that integer, unlike '1.9', which means something else. Asserting
            // it here would also send the test at a real endpoint, because a
            // resolved server goes on to an actual import.

        ];
    }

    #[Test]
    #[DataProvider('unusableServerValues')]
    public function answersNotFoundForAValueThatIsNotAUid(mixed $server): void
    {
        $response = $this->controller()->importAction(
            (new ServerRequest('POST', '/ajax/nrllm/mcp/import'))->withParsedBody(['server' => $server]),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * The success path of the connection test, end to end.
     *
     * Everything the handshake learned beyond the latency is stored nowhere
     * (ADR-154), so this response IS the report — if it does not carry the
     * protocol revision and the server's self-description, no operator ever
     * sees them. The refreshed contact line rides along because the module
     * writes it into the card instead of reloading the page, and a reload is
     * what would otherwise destroy the report.
     */
    #[Test]
    public function theConnectionTestAnswersWithTheReportTheModuleRenders(): void
    {
        $fake = (new McpTestServer())->willReturn([
            'protocolVersion' => '2025-03-26',
            'serverInfo'      => ['name' => 'Example MCP', 'version' => '4.2'],
        ]);

        $transport = $this->get(McpHttpTransport::class);
        self::assertInstanceOf(McpHttpTransport::class, $transport);
        $transport->setHttpClient($fake);

        $response = $this->controller()->testConnectionAction(
            (new ServerRequest('POST', '/ajax/nrllm/mcp/test'))->withParsedBody(['server' => 1]),
        );

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);

        // The handshake, and nothing that could rewrite the catalogue.
        self::assertSame(['initialize', 'notifications/initialized'], $fake->methods());

        // The three facts no column holds.
        assert(is_string($payload['report']));
        self::assertStringContainsString('2025-03-26', $payload['report']);
        self::assertStringContainsString('Example MCP 4.2', $payload['report']);
        self::assertMatchesRegularExpression('/\d+ ms/', $payload['report']);

        // The line that replaces "Never reached" on the card, from the row the
        // ping just stamped.
        assert(is_string($payload['contact']));
        self::assertStringContainsString(date('Y-m-d'), $payload['contact']);
        self::assertStringContainsString(' ms', $payload['contact']);

        $stamped = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $stamped);
        $lastContact = $stamped->getConnectionForTable('tx_nrllm_mcp_server')
            ->fetchOne('SELECT last_contact FROM tx_nrllm_mcp_server WHERE uid = 1');
        self::assertGreaterThan(0, (int)$lastContact, 'the probe recorded the contact it reports');
    }

    /**
     * An unreachable server is a finding, not a failure of the request: the
     * shared front-end helper shows `error` and leaves the page alone.
     */
    #[Test]
    public function theConnectionTestReportsAnUnreachableServerWithoutAReport(): void
    {
        $fake      = (new McpTestServer())->willReturnRaw('', 503);
        $transport = $this->get(McpHttpTransport::class);
        self::assertInstanceOf(McpHttpTransport::class, $transport);
        $transport->setHttpClient($fake);

        $response = $this->controller()->testConnectionAction(
            (new ServerRequest('POST', '/ajax/nrllm/mcp/test'))->withParsedBody(['server' => 1]),
        );

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
        assert(is_string($payload['error']));
        self::assertStringContainsString('503', $payload['error']);
        self::assertArrayNotHasKey('report', $payload, 'there is nothing to render for a server that did not answer');
    }

    /**
     * The module is what makes the health columns worth having (ADR-154), so
     * the claim "an operator can see it" is only true if it renders.
     */
    #[Test]
    public function theListRendersEverythingAnOperatorNeedsToJudgeAServer(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('tx_nrllm_mcp_server')->update(
            'tx_nrllm_mcp_server',
            [
                'auth_credential' => 'e6f1a2b3-0000-4000-8000-000000000001',
                'auth_placement'  => 'header',
                'auth_header_name' => 'X-Api-Key',
                'tool_count'      => 7,
                'last_contact'    => 1_700_000_500,
                'last_latency_ms' => 142,
                'import_status'   => 'error',
                'import_error'    => 'the previous import failed',
            ],
            ['uid' => 1],
        );

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($this->setUpBackendUser(1));

        $controller = $this->controller();
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $body = (string)$controller->listAction()->getBody();

        // Liveness, and the latency that came with it.
        self::assertStringContainsString('2023-11-14', $body, 'the last successful contact is dated');
        self::assertStringContainsString('142 ms', $body);
        // Transport is stated even though no column holds it.
        self::assertStringContainsString('HTTP (JSON-RPC)', $body);
        // The authentication mode as CONFIGURED — the header name included.
        self::assertStringContainsString('X-Api-Key', $body);
        // The credential itself is a vault identifier and must never surface.
        self::assertStringNotContainsString('e6f1a2b3-0000-4000-8000-000000000001', $body);
        // Catalogue size, data class and the standing error.
        self::assertStringContainsString('>7<', $body);
        self::assertStringContainsString('publicContent', $body);
        self::assertStringContainsString('the previous import failed', $body);
        // And the action that answers "is it alive" without rewriting anything.
        self::assertStringContainsString('data-nrllm-mcp-test="1"', $body);
    }

    /**
     * Build an Extbase backend request carrying the route package name so the
     * BackendViewFactory resolves the extension's template root path.
     */
    private function createBackendRequest(): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\McpServer');
        $extbaseParameters->setControllerActionName('list');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new Typo3ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/mcp', ['packageName' => 'netresearch/nr-llm']))
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
