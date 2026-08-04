<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\McpServerController;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;

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
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);

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
}
