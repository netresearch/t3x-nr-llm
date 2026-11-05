<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Controller\Backend\McpOAuthCallbackController;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthAuthorizer;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthDiscovery;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthGrant;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthGrantRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthHttp;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthRegistrar;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthSecrets;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthSessionStore;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthTokenClient;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\OAuthStubServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\OAuthVaultTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The redirect target of the OAuth flow (ADR-259): administrator only, a
 * state from this session only, and each state once.
 */
#[CoversClass(McpOAuthCallbackController::class)]
#[CoversClass(McpOAuthSessionStore::class)]
final class McpOAuthCallbackControllerTest extends AbstractFunctionalTestCase
{
    use OAuthVaultTrait;

    private const NOW = 1_800_000_000;

    private OAuthStubServer $stub;

    private McpOAuthAuthorizer $authorizer;

    private McpOAuthGrantRepository $grants;

    private McpServerRepository $servers;

    private McpOAuthSessionStore $sessions;

    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3.example.com/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $this->stub = new OAuthStubServer();
        $factory    = $this->hostFactory();
        $vault      = $this->inMemoryVault($this->stub, $factory);
        $pool       = $this->getService(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->grants     = new McpOAuthGrantRepository($pool);
        $this->servers    = new McpServerRepository($pool);
        $http             = new McpOAuthHttp($vault, $factory);
        $secrets          = new McpOAuthSecrets($vault);
        $this->authorizer = new McpOAuthAuthorizer(
            new McpOAuthDiscovery($http),
            new McpOAuthRegistrar($http, $secrets),
            new McpOAuthTokenClient($http),
            $secrets,
            $this->grants,
        );
        $this->sessions = new McpOAuthSessionStore();
        $this->context  = new Context();
        $this->context->setAspect('date', new DateTimeAspect(new DateTimeImmutable('@' . self::NOW)));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);

        parent::tearDown();
    }

    #[Test]
    public function aStateFromThisSessionConnectsTheServerOnce(): void
    {
        $user   = $this->setUpBackendUser(1);
        $server = $this->insertServer();
        $answer = $this->startFor($user, $server);

        $response = $this->controller()->handleAction($this->callbackRequest($answer));

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('nrllm', urldecode($response->getHeaderLine('Location')));
        self::assertSame(['server' => $server->uid, 'result' => 'connected'], $this->sessions->takeResult($user));
        $grant = $this->grants->findByServer($server->uid);
        self::assertInstanceOf(McpOAuthGrant::class, $grant);
        self::assertSame(1, $grant->connectedBy);

        // The same answer again finds no flow: a state works once.
        $this->controller()->handleAction($this->callbackRequest($answer));
        self::assertSame(['server' => 0, 'result' => 'state_invalid'], $this->sessions->takeResult($user));
        self::assertCount(1, $this->stub->tokenForms);
    }

    #[Test]
    public function anUnknownStateRedeemsNothing(): void
    {
        $user            = $this->setUpBackendUser(1);
        $server          = $this->insertServer();
        $answer          = $this->startFor($user, $server);
        $answer['state'] = 'forged';

        $this->controller()->handleAction($this->callbackRequest($answer));

        self::assertSame(['server' => 0, 'result' => 'state_invalid'], $this->sessions->takeResult($user));
        self::assertSame([], $this->stub->tokenForms);
        self::assertNull($this->grants->findByServer($server->uid));
    }

    #[Test]
    public function aNonAdministratorIsRefusedBeforeTheStateIsRead(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $server = $this->insertServer();
        $answer = $this->startFor($admin, $server);
        $this->setUpBackendUser(2);

        $response = $this->controller()->handleAction($this->callbackRequest($answer));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->stub->tokenForms);
    }

    #[Test]
    public function aServerDeletedDuringTheFlowIsNotConnected(): void
    {
        $this->stub->registrationReturnsSecret = true;
        $user                                  = $this->setUpBackendUser(1);
        $server                                = $this->insertServer();
        $answer                                = $this->startFor($user, $server);
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_mcp_server')->update('tx_nrllm_mcp_server', ['deleted' => 1], ['uid' => $server->uid]);

        $this->controller()->handleAction($this->callbackRequest($answer));

        self::assertSame(['server' => $server->uid, 'result' => 'state_invalid'], $this->sessions->takeResult($user));
        self::assertSame([], $this->stub->tokenForms);
        self::assertSame([], $this->vaultSecrets, 'the client secret the flow registered was removed');
    }

    /**
     * @return array<string, string>
     */
    private function startFor(BackendUserAuthentication $user, McpServerRecord $server): array
    {
        $request = $this->authorizer->start($server, 'https://typo3.example.com/typo3/nrllm/mcp/oauth/callback', 1, self::NOW);
        $this->sessions->remember($user, $request->pending, self::NOW);

        return $this->stub->authorize($request->authorizationUrl);
    }

    /**
     * @param array<string, string> $answer
     */
    private function callbackRequest(array $answer): ServerRequest
    {
        return (new ServerRequest('https://typo3.example.com/typo3/nrllm/mcp/oauth/callback?' . http_build_query($answer)))
            ->withQueryParams($answer);
    }

    private function controller(): McpOAuthCallbackController
    {
        $uriBuilder = $this->getService(UriBuilder::class);
        self::assertInstanceOf(UriBuilder::class, $uriBuilder);

        return new McpOAuthCallbackController($this->servers, $this->authorizer, $this->sessions, $uriBuilder, $this->context);
    }

    private function insertServer(): McpServerRecord
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_mcp_server');
        $connection->insert('tx_nrllm_mcp_server', [
            'pid'        => 0,
            'identifier' => 'secured',
            'name'       => 'Secured',
            'url'        => OAuthStubServer::MCP_URL,
            'auth_mode'  => 'oauth',
            'data_class' => 'publicContent',
            'enabled'    => 1,
        ]);

        $server = $this->servers->findByUid((int) $connection->lastInsertId());
        self::assertInstanceOf(McpServerRecord::class, $server);

        return $server;
    }
}
