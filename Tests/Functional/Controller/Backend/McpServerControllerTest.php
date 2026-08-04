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
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
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
     * A uid that is not a number must not coerce to 0 and then to "no server":
     * it has to take the same explicit path, so a malformed request is never
     * one integer cast away from naming a real row.
     */
    #[Test]
    public function answersNotFoundForANonNumericServer(): void
    {
        $response = $this->controller()->importAction(
            (new ServerRequest('POST', '/ajax/nrllm/mcp/import'))->withParsedBody(['server' => 'first']),
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
