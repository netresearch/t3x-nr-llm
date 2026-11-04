<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp\OAuth;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\ConfiguredMcpSubjectResolver;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthenticationSessionFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHealthRecorder;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthAuthorizer;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthCredentialSession;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthDiscovery;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthException;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthGrant;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthGrantRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthHttp;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthPkce;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthRegistrar;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthSecrets;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthTokenClient;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthTokenStore;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\OAuthStubServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\OAuthVaultTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * The OAuth connection of an MCP server, end to end against a stub
 * authorization server and MCP server (ADR-259).
 *
 * Every request goes through nr-vault's real HTTP client, so the Bearer header
 * on the MCP call and the refresh token in the token form were written by
 * Vault from the stored secret; the grant row and the server row are real
 * database rows. This is the proof the ADR names in place of a live one: the
 * demo has no OAuth-protected MCP server.
 */
#[CoversClass(McpOAuthAuthorizer::class)]
#[CoversClass(McpOAuthDiscovery::class)]
#[CoversClass(McpOAuthRegistrar::class)]
#[CoversClass(McpOAuthTokenClient::class)]
#[CoversClass(McpOAuthTokenStore::class)]
#[CoversClass(McpOAuthCredentialSession::class)]
#[CoversClass(McpOAuthGrantRepository::class)]
#[CoversClass(McpOAuthHttp::class)]
final class McpOAuthFlowTest extends AbstractFunctionalTestCase
{
    use OAuthVaultTrait;

    private const REDIRECT_URI = 'https://typo3.example.com/typo3/nrllm/mcp/oauth/callback';

    private const START = 1_800_000_000;

    private OAuthStubServer $stub;

    private VaultServiceInterface $vault;

    private McpServerRepository $servers;

    private McpOAuthGrantRepository $grants;

    private McpOAuthAuthorizer $authorizer;

    private McpOAuthTokenStore $tokenStore;

    private MockClock $clock;

    private McpOAuthHttp $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stub  = new OAuthStubServer();
        $factory     = $this->hostFactory();
        $this->vault = $this->inMemoryVault($this->stub, $factory);
        $pool        = $this->getService(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->servers = new McpServerRepository($pool);
        $this->grants  = new McpOAuthGrantRepository($pool);
        $this->clock   = new MockClock('@' . self::START);

        $this->http       = new McpOAuthHttp($this->vault, $factory);
        $secrets          = new McpOAuthSecrets($this->vault);
        $tokens           = new McpOAuthTokenClient($this->http);
        $this->authorizer = new McpOAuthAuthorizer(
            new McpOAuthDiscovery($this->http),
            new McpOAuthRegistrar($this->http, $secrets),
            $tokens,
            $secrets,
            $this->grants,
        );
        $this->tokenStore = new McpOAuthTokenStore($this->grants, $tokens, $secrets, $this->clock);
    }

    #[Test]
    public function aConnectedServerIsCalledWithItsTokenAndRefreshedBeforeExpiry(): void
    {
        $server = $this->insertServer();
        $grant  = $this->connect($server);

        // The probe that started discovery carried no credential at all.
        self::assertSame('POST', $this->stub->requests[0]['method']);
        self::assertSame(OAuthStubServer::MCP_URL, $this->stub->requests[0]['url']);
        self::assertSame('', $this->stub->requests[0]['authorization']);

        // RFC 7591: a public web client with the callback as its only URI.
        self::assertSame('web', $this->stub->registrations[0]['application_type']);
        self::assertSame('none', $this->stub->registrations[0]['token_endpoint_auth_method']);
        self::assertSame([self::REDIRECT_URI], $this->stub->registrations[0]['redirect_uris']);

        // The code was redeemed with the verifier and the resource.
        $redeem = $this->stub->tokenForms[0];
        self::assertSame('authorization_code', $redeem['grant_type']);
        self::assertSame(OAuthStubServer::MCP_URL, $redeem['resource']);
        self::assertSame(self::REDIRECT_URI, $redeem['redirect_uri']);
        self::assertNotSame('', $redeem['code_verifier']);

        // Tokens are Vault secrets; the grant row holds references only.
        self::assertSame('access-2', $this->secretValue($grant->accessCredential));
        self::assertSame('refresh-2', $this->secretValue($grant->refreshCredential));
        self::assertSame(self::START + 3600, $grant->expiresAt);
        self::assertSame('mcp:tools offline_access', $grant->scope);
        $row = $this->getConnection()->select(['*'], 'tx_nrllm_mcp_oauth_grant')->fetchAssociative();
        self::assertIsArray($row);
        foreach ($row as $value) {
            $text = is_scalar($value) ? (string) $value : '';
            self::assertStringNotContainsString('access-', $text);
            self::assertStringNotContainsString('refresh-', $text);
        }

        $client = $this->client();
        $tools  = $client->listTools($server);
        self::assertSame('whoami', $tools[0]['name'] ?? null);
        self::assertSame('Bearer access-2', $this->lastMcpAuthorization());

        // Thirty seconds before expiry the next call refreshes first, under
        // the same Vault references, and then calls with the new token.
        $this->setNow(self::START + 3570);
        $outcome = $client->callTool($server, 'whoami', []);
        self::assertFalse($outcome->isError);
        self::assertSame('token access-3', $outcome->text);
        $refresh = $this->stub->tokenForms[1];
        self::assertSame('refresh_token', $refresh['grant_type']);
        self::assertSame('refresh-2', $refresh['refresh_token'], 'Vault wrote the stored refresh token into the form');
        self::assertSame(OAuthStubServer::MCP_URL, $refresh['resource']);
        self::assertSame('access-3', $this->secretValue($grant->accessCredential));
        self::assertSame('refresh-3', $this->secretValue($grant->refreshCredential), 'the rotated refresh token replaced the old one');
        $after = $this->grants->findByServer($server->uid);
        self::assertInstanceOf(McpOAuthGrant::class, $after);
        self::assertSame(self::START + 3570 + 3600, $after->expiresAt);
        self::assertSame('Bearer access-3', $this->lastMcpAuthorization());

        // A token that is still good is not refreshed again.
        $client->callTool($server, 'whoami', []);
        self::assertCount(2, $this->stub->tokenForms);
    }

    #[Test]
    public function anExpiredTokenWaitsForTheRefreshAnotherProcessHoldsAndDoesNotRepeatIt(): void
    {
        $server = $this->insertServer();
        $grant  = $this->connect($server);
        $this->setNow(self::START + 3605);
        // Another process, on any host, holds the lease on the row.
        $theirs = $this->grants->acquireLease($server->uid, self::START + 3604, 30);
        self::assertNotNull($theirs);

        $pauses = 0;
        $store  = $this->storeWithPause(function () use ($server, $theirs, &$pauses): void {
            // While this process waits, the other one finishes its refresh.
            ++$pauses;
            $this->grants->recordRefresh($server->uid, self::START + 7200, 'mcp:tools', self::START + 3605);
            $this->grants->releaseLease($server->uid, $theirs);
        });

        self::assertSame($grant->accessCredential, $store->accessCredential($server, 5));
        self::assertSame(1, $pauses);
        self::assertCount(1, $this->stub->tokenForms, 'only the code redemption reached the token endpoint');
    }

    #[Test]
    public function aTokenThatHasNotExpiredIsUsedWhileAnotherProcessRefreshes(): void
    {
        $server = $this->insertServer();
        $grant  = $this->connect($server);
        $this->setNow(self::START + 3590);
        self::assertNotNull($this->grants->acquireLease($server->uid, self::START + 3589, 30));

        $store = $this->storeWithPause(static function (): never {
            self::fail('A caller with a token that is still good waited.');
        });

        self::assertSame($grant->accessCredential, $store->accessCredential($server, 5));
        self::assertCount(1, $this->stub->tokenForms);
    }

    #[Test]
    public function aLeaseIsTakenOnceAndOnlyItsOwnerReleasesIt(): void
    {
        $server = $this->insertServer();
        $this->connect($server);

        $first = $this->grants->acquireLease($server->uid, self::START, 30);
        self::assertSame(self::START + 30, $first);
        self::assertNull($this->grants->acquireLease($server->uid, self::START + 10, 30), 'a held lease is not taken twice');
        $second = $this->grants->acquireLease($server->uid, self::START + 31, 30);
        self::assertSame(self::START + 61, $second, 'an abandoned lease runs out');

        // The first holder, late, gives back what it took — not the second one's lease.
        $this->grants->releaseLease($server->uid, $first);
        self::assertNull($this->grants->acquireLease($server->uid, self::START + 40, 30));
        $this->grants->releaseLease($server->uid, $second);

        $this->setNow(self::START + 3590);
        $this->tokenStore->accessCredential($server, 5);
        self::assertCount(2, $this->stub->tokenForms);
        self::assertNotNull($this->grants->acquireLease($server->uid, self::START + 3591, 30), 'the refresh released its lease');
    }

    #[Test]
    public function theLeaseOutlastsTheRefreshRequestItGuards(): void
    {
        $server = $this->insertServer();
        $this->connect($server);
        $this->setNow(self::START + 3590);
        $held                = null;
        $this->stub->onToken = function () use (&$held): void {
            $held = $this->getConnection()->select(['refreshing_until'], 'tx_nrllm_mcp_oauth_grant')->fetchOne();
        };

        $this->tokenStore->accessCredential($server, 120);

        self::assertSame(self::START + 3590 + 120 + McpOAuthTokenStore::LEASE_MARGIN_SECONDS, is_numeric($held) ? (int) $held : -1);
    }

    #[Test]
    public function aTokenTheServerRetiredEarlyIsRenewedAndTheRequestSentOnce(): void
    {
        $server = $this->insertServer();
        $this->connect($server);
        $this->stub->retireAccessToken();

        $outcome = $this->client()->callTool($server, 'whoami', []);

        self::assertFalse($outcome->isError);
        self::assertSame('token access-3', $outcome->text);
        self::assertCount(2, $this->stub->tokenForms);
        self::assertSame('refresh_token', $this->stub->tokenForms[1]['grant_type']);
    }

    #[Test]
    public function aRetiredTokenWithoutARefreshTokenFailsWithoutARetry(): void
    {
        $this->stub->issueRefreshToken = false;
        $server                        = $this->insertServer();
        $this->connect($server);
        $this->stub->retireAccessToken();
        $before = count($this->stub->requests);

        self::assertStringContainsString('token_rejected', $this->failure(fn (): mixed => $this->client()->callTool($server, 'whoami', [])));
        self::assertSame(1, count($this->stub->requests) - $before, 'one refused request, no retry');
    }

    #[Test]
    public function theNewRefreshTokenIsKeptWhenStoringTheAccessTokenFails(): void
    {
        $server                 = $this->insertServer();
        $grant                  = $this->connect($server);
        $this->failingRotations = [$grant->accessCredential];
        $this->setNow(self::START + 3590);

        self::assertStringContainsString('vault_rotate_failed', $this->failure(fn (): mixed => $this->tokenStore->accessCredential($server, 5)));
        self::assertSame('refresh-3', $this->secretValue($grant->refreshCredential), 'the refresh token the server now expects is stored');

        // The next look renews with it.
        $this->failingRotations = [];
        $this->tokenStore->accessCredential($server, 5);
        self::assertSame('refresh-3', $this->stub->tokenForms[2]['refresh_token']);
        self::assertSame('access-4', $this->secretValue($grant->accessCredential));
    }

    #[Test]
    public function aGrantIsNotUsedForAServerWhoseUrlWasChangedAfterwards(): void
    {
        $server = $this->insertServer();
        $this->connect($server);
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_mcp_server')->update(
            'tx_nrllm_mcp_server',
            ['url' => 'https://elsewhere.example.com/mcp'],
            ['uid' => $server->uid],
        );
        $moved = $this->servers->findByUid($server->uid);
        self::assertInstanceOf(McpServerRecord::class, $moved);
        $requests = count($this->stub->requests);

        self::assertStringContainsString('reconnect_required', $this->failure(fn (): mixed => $this->client()->callTool($moved, 'whoami', [])));
        self::assertCount($requests, $this->stub->requests, 'the token went nowhere');
    }

    #[Test]
    public function aRefusedRefreshMarksTheGrantForReconnectAndFailsTheCallWithoutTheToken(): void
    {
        $server = $this->insertServer();
        $this->connect($server);
        $this->stub->revoke();
        $this->setNow(self::START + 3590);

        $message = $this->failure(fn (): mixed => $this->client()->callTool($server, 'whoami', []));

        self::assertStringContainsString('reconnect_required', $message);
        self::assertStringNotContainsString('refresh-', $message);
        $grant = $this->grants->findByServer($server->uid);
        self::assertInstanceOf(McpOAuthGrant::class, $grant);
        self::assertSame(McpOAuthGrant::STATUS_RECONNECT, $grant->status);

        // Later calls do not try the refused grant again.
        $requests = count($this->stub->requests);
        self::assertStringContainsString('reconnect_required', $this->failure(fn (): mixed => $this->client()->callTool($server, 'whoami', [])));
        self::assertCount($requests, $this->stub->requests);
    }

    #[Test]
    public function aResponseFromAnotherIssuerIsDiscardedBeforeTheCodeIsRedeemed(): void
    {
        $server        = $this->insertServer();
        $request       = $this->authorizer->start($server, self::REDIRECT_URI, 1, self::START);
        $answer        = $this->stub->authorize($request->authorizationUrl);
        $answer['iss'] = 'https://auth.example.com/other';

        try {
            $this->authorizer->complete($server, $request->pending, $answer, 1, self::START + 5);
            self::fail('A response from another issuer was used.');
        } catch (McpOAuthException $exception) {
            self::assertSame('iss_mismatch', $exception->reason());
        }

        self::assertSame([], $this->stub->tokenForms);
        self::assertNull($this->grants->findByServer($server->uid));
    }

    #[Test]
    public function aRegisteredSecretIsSentByVaultAndRemovedWithTheConnection(): void
    {
        $this->stub->registrationReturnsSecret = true;
        $server                                = $this->insertServer();
        $grant                                 = $this->connect($server);

        self::assertSame('registered-secret-client-1', $this->secretValue($grant->clientSecretCredential));
        self::assertSame('registered-secret-client-1', $this->stub->tokenForms[0]['client_secret']);

        $this->authorizer->disconnect($server);

        self::assertNull($this->grants->findByServer($server->uid));
        self::assertSame([], $this->vaultSecrets);
    }

    #[Test]
    public function aSecondConnectReusesTheRegistrationAndReplacesTheTokens(): void
    {
        $server = $this->insertServer();
        $first  = $this->connect($server);
        $second = $this->connect($server);

        self::assertCount(1, $this->stub->registrations);
        self::assertSame($first->clientId, $second->clientId);
        self::assertNotSame($first->accessCredential, $second->accessCredential);
        self::assertContains($first->accessCredential, $this->vaultDeleted);
        self::assertContains($first->refreshCredential, $this->vaultDeleted);
    }

    #[Test]
    public function anUnconnectedServerIsRefusedWithoutAnUnauthenticatedCall(): void
    {
        $server = $this->insertServer();

        self::assertStringContainsString('not_connected', $this->failure(fn (): mixed => $this->client()->callTool($server, 'whoami', [])));
        self::assertSame([], $this->stub->requests);
    }

    #[Test]
    public function theAuthorizationRequestCarriesPkceStateAndResource(): void
    {
        $server  = $this->insertServer();
        $request = $this->authorizer->start($server, self::REDIRECT_URI, 1, self::START);
        parse_str((string) parse_url($request->authorizationUrl, PHP_URL_QUERY), $query);

        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(McpOAuthPkce::challenge($request->pending->verifier), $query['code_challenge']);
        self::assertSame($request->pending->state, $query['state']);
        self::assertSame(OAuthStubServer::MCP_URL, $query['resource']);
        // The challenge's scope, plus offline_access the server lists.
        self::assertSame('mcp:tools offline_access', $query['scope']);
        self::assertSame(OAuthStubServer::ISSUER, $request->pending->issuer);
    }

    #[Test]
    public function theRecordCarriesTheOAuthColumns(): void
    {
        $server = $this->insertServer(['oauth_client_id' => 'pre-1', 'oauth_scopes' => 'a b', 'oauth_client_secret' => 'ref-1']);

        self::assertSame('pre-1', $server->oauthClientId);
        self::assertSame('ref-1', $server->oauthClientSecret);
        self::assertSame('a b', $server->oauthScopes);
    }

    private function connect(McpServerRecord $server): McpOAuthGrant
    {
        $request = $this->authorizer->start($server, self::REDIRECT_URI, 1, self::START);

        return $this->authorizer->complete(
            $server,
            $request->pending,
            $this->stub->authorize($request->authorizationUrl),
            1,
            self::START,
        );
    }

    private function client(): McpClient
    {
        $clock     = new FakeMcpClock();
        $transport = new McpHttpTransport($this->vault, $this->hostFactory(), new RequestFactory(new GuzzleClientFactory()), new StreamFactory());
        $config    = self::createStub(ExtensionConfiguration::class);

        return new McpClient(
            $transport,
            new McpHealthRecorder($this->servers, new Context(), new NullLogger()),
            new McpDeadlineFactory($clock, $config),
            new McpAuthenticationSessionFactory(
                $config,
                new ConfiguredMcpSubjectResolver($config),
                $this->vault,
                $this->hostFactory(),
                $clock,
                $this->tokenStore,
            ),
        );
    }

    /**
     * The message of the transport failure the call ends in. The client lets
     * it escape; {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpTool} turns it
     * into the failed tool result a run sees.
     *
     * @param callable(): mixed $call
     */
    private function failure(callable $call): string
    {
        try {
            $call();
        } catch (McpTransportException $exception) {
            return $exception->getMessage();
        }

        self::fail('The call succeeded.');
    }

    /**
     * @param Closure(): void $pause
     */
    private function storeWithPause(Closure $pause): McpOAuthTokenStore
    {
        return new McpOAuthTokenStore(
            $this->grants,
            new McpOAuthTokenClient($this->http),
            new McpOAuthSecrets($this->vault),
            $this->clock,
            $pause,
        );
    }

    private function lastMcpAuthorization(): string
    {
        $last = '';
        foreach ($this->stub->requests as $request) {
            if ($request['url'] === OAuthStubServer::MCP_URL) {
                $last = $request['authorization'];
            }
        }

        return $last;
    }

    private function setNow(int $timestamp): void
    {
        $this->clock->modify('@' . $timestamp);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertServer(array $overrides = []): McpServerRecord
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_mcp_server');
        $connection->insert('tx_nrllm_mcp_server', $overrides + [
            'pid'               => 0,
            'identifier'        => 'secured',
            'name'              => 'Secured',
            'url'               => OAuthStubServer::MCP_URL,
            'auth_mode'         => 'oauth',
            'data_class'        => 'publicContent',
            'requires_approval' => 1,
            'enabled'           => 1,
        ]);

        $server = $this->servers->findByUid((int) $connection->lastInsertId());
        self::assertInstanceOf(McpServerRecord::class, $server);

        return $server;
    }
}
