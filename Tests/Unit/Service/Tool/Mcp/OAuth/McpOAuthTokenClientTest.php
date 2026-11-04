<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\OAuth;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthClientRegistration;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthException;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthGrant;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthHttp;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthMetadata;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthPendingAuthorization;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthRegistrar;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthSecrets;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthTokenClient;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthTokenResponse;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\OAuthVaultTrait;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\RoutedWire;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Token requests and client registration on the wire (ADR-259).
 */
#[CoversClass(McpOAuthTokenClient::class)]
#[CoversClass(McpOAuthTokenResponse::class)]
#[CoversClass(McpOAuthRegistrar::class)]
#[CoversClass(McpOAuthClientRegistration::class)]
#[CoversClass(McpOAuthSecrets::class)]
#[CoversClass(McpOAuthHttp::class)]
final class McpOAuthTokenClientTest extends AbstractUnitTestCase
{
    use OAuthVaultTrait;

    private const TOKEN = 'https://auth.example.com/token';

    private const REGISTER = 'https://auth.example.com/register';

    private const REFRESH_REF = '01900000-0000-7000-8000-000000000001';

    private const SECRET_REF = '01900000-0000-7000-8000-000000000002';

    private RoutedWire $wire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wire = new RoutedWire();
    }

    #[Test]
    public function aRefreshSendsTheStoredSecretsThroughVaultAndTheResource(): void
    {
        $client                                = $this->tokenClient();
        $this->vaultSecrets[self::REFRESH_REF] = ['value' => 'stored-refresh', 'options' => []];
        $this->vaultSecrets[self::SECRET_REF]  = ['value' => 'stored-secret', 'options' => []];
        $this->wire->json(self::TOKEN, ['access_token' => 'a', 'token_type' => 'bearer', 'expires_in' => '120', 'refresh_token' => 'r', 'scope' => 'x x y']);

        $response = $client->refresh($this->grant('dynamic', 'client', 'https://auth.example.com', self::SECRET_REF, self::REFRESH_REF), 5);

        parse_str((string) $this->wire->requests[0]->getBody(), $form);
        ksort($form);
        self::assertSame(
            [
                'client_id'     => 'client',
                'client_secret' => 'stored-secret',
                'grant_type'    => 'refresh_token',
                'refresh_token' => 'stored-refresh',
                'resource'      => 'https://mcp.example.com/mcp',
            ],
            $form,
        );
        self::assertSame('a', $response->accessToken);
        self::assertSame(120, $response->expiresIn);
        self::assertSame('x y', $response->scope);
        $response->forget();
        self::assertSame('', $response->accessToken);
        self::assertSame('', $response->refreshToken);
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: string}>
     */
    public static function refusedRefreshes(): iterable
    {
        yield 'invalid_grant' => [400, '{"error":"invalid_grant"}', 'reconnect_required'];
        yield 'invalid_client' => [401, '{"error":"invalid_client"}', 'reconnect_required'];
        yield 'server error' => [503, '{"error":"temporarily_unavailable"}', 'refresh_failed'];
        yield 'other 400' => [400, '{"error":"invalid_request"}', 'refresh_failed'];
        yield 'not json' => [400, '<html>', 'refresh_failed'];
    }

    #[Test]
    #[DataProvider('refusedRefreshes')]
    public function onlyARefusedGrantAsksForAReconnect(int $status, string $body, string $reason): void
    {
        $this->vaultSecrets[self::REFRESH_REF] = ['value' => 'stored-refresh', 'options' => []];
        $this->wire->route(self::TOKEN, new Response($status, ['Content-Type' => 'application/json'], $body));

        $this->expectReason($reason, fn (): mixed => $this->tokenClient()->refresh($this->grant('dynamic', 'client', 'https://auth.example.com', '', self::REFRESH_REF), 5));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidTokenResponses(): iterable
    {
        yield 'no access token' => [['token_type' => 'Bearer'], 'token_invalid'];
        yield 'access token with a space' => [['access_token' => "a\r\nX-Injected: 1", 'token_type' => 'Bearer'], 'token_invalid'];
        yield 'mac token' => [['access_token' => 'a', 'token_type' => 'mac'], 'token_type_unsupported'];
        yield 'negative lifetime' => [['access_token' => 'a', 'token_type' => 'Bearer', 'expires_in' => -1], 'token_invalid'];
        yield 'refresh token that is not a string' => [['access_token' => 'a', 'token_type' => 'Bearer', 'refresh_token' => 7], 'token_invalid'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('invalidTokenResponses')]
    public function aTokenResponseThatCouldNotBeUsedSafelyIsRefused(array $payload, string $reason): void
    {
        $this->wire->json(self::TOKEN, $payload);

        $this->expectReason($reason, fn (): mixed => $this->tokenClient()->redeemCode($this->pending(), 'code', 5));
    }

    #[Test]
    public function aRedirectIsNotFollowed(): void
    {
        $this->wire->route(self::TOKEN, new Response(302, ['Location' => 'https://evil.example.com/token']));

        $this->expectReason('token_refused', fn (): mixed => $this->tokenClient()->redeemCode($this->pending(), 'code', 5));
        self::assertCount(1, $this->wire->requests);
    }

    #[Test]
    public function anOversizedBodyIsRefused(): void
    {
        $this->wire->route(self::TOKEN, new Response(200, ['Content-Type' => 'application/json'], str_repeat(' ', McpOAuthHttp::MAX_BODY_BYTES + 1)));

        $this->expectReason('response_too_large', fn (): mixed => $this->tokenClient()->redeemCode($this->pending(), 'code', 5));
    }

    #[Test]
    public function dynamicRegistrationAsksForAPublicWebClientAndStoresAnIssuedSecret(): void
    {
        $this->wire->json(self::REGISTER, ['client_id' => 'issued', 'client_secret' => 'shh', 'token_endpoint_auth_method' => 'client_secret_post'], 201);

        $client = $this->registrar()->clientFor($this->server(), $this->metadata(), 'https://typo3.example.com/cb', null, 7, 5);

        $body = json_decode((string) $this->wire->requests[0]->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('web', $body['application_type']);
        self::assertSame('none', $body['token_endpoint_auth_method']);
        self::assertSame(['https://typo3.example.com/cb'], $body['redirect_uris']);
        self::assertSame(['authorization_code', 'refresh_token'], $body['grant_types']);
        self::assertSame('issued', $client->clientId);
        self::assertTrue($client->created);
        self::assertSame('shh', $this->secretValue($client->clientSecretCredential));
        self::assertSame(7, $this->vaultSecrets[$client->clientSecretCredential]['options']['owner']);
        self::assertSame([], $this->vaultSecrets[$client->clientSecretCredential]['options']['groups']);
    }

    #[Test]
    public function aPreRegisteredClientComesFirstAndIsBoundToItsIssuer(): void
    {
        $server = $this->server('pre-client', 'pre-secret-ref');

        $client = $this->registrar()->clientFor($server, $this->metadata(), 'https://typo3.example.com/cb', null, 7, 5);
        self::assertSame('pre-client', $client->clientId);
        self::assertSame('pre-secret-ref', $client->clientSecretCredential);
        self::assertSame([], $this->wire->requests);

        $this->expectReason('issuer_changed', fn (): mixed => $this->registrar()->clientFor($server, $this->metadata(), 'https://typo3.example.com/cb', $this->grant('pre', 'pre-client', 'https://old.example.com'), 7, 5));
    }

    #[Test]
    public function aDynamicRegistrationIsReusedOnlyForTheSameIssuerAndRedirect(): void
    {
        $existing = $this->grant('dynamic', 'old-client', 'https://auth.example.com');
        $reused   = $this->registrar()->clientFor($this->server(), $this->metadata(), 'https://typo3.example.com/cb', $existing, 7, 5);
        self::assertSame('old-client', $reused->clientId);
        self::assertFalse($reused->created);

        $this->wire->json(self::REGISTER, ['client_id' => 'new-client'], 201);
        $moved = $this->registrar()->clientFor($this->server(), $this->metadata(), 'https://other.example.com/cb', $existing, 7, 5);
        self::assertSame('new-client', $moved->clientId);
    }

    #[Test]
    public function aGrantTheServerRefusedDoesNotLendItsClientToTheReconnect(): void
    {
        $refused = new McpOAuthGrant(1, 'https://auth.example.com', self::TOKEN, 'https://mcp.example.com/mcp', 'https://typo3.example.com/cb', 'dead-client', '', 'dynamic', 'a', 'r', 0, '', McpOAuthGrant::STATUS_RECONNECT, 7, 0);
        $this->wire->json(self::REGISTER, ['client_id' => 'fresh-client'], 201);

        $client = $this->registrar()->clientFor($this->server(), $this->metadata(), 'https://typo3.example.com/cb', $refused, 7, 5);

        self::assertSame('fresh-client', $client->clientId);
    }

    #[Test]
    public function aClientWithASecretIsRefusedWhenVaultCannotSendTwoSecrets(): void
    {
        $this->wire->json(self::REGISTER, ['client_id' => 'issued', 'client_secret' => 'shh', 'token_endpoint_auth_method' => 'client_secret_post'], 201);

        $this->expectReason('vault_capability_missing', fn (): mixed => $this->registrar(false)->clientFor($this->server(), $this->metadata(), 'https://typo3.example.com/cb', null, 7, 5));
        self::assertSame([], $this->vaultSecrets, 'the secret it registered was removed');

        $this->expectReason('vault_capability_missing', fn (): mixed => $this->registrar(false)->clientFor($this->server('pre-client', self::SECRET_REF), $this->metadata(), 'https://typo3.example.com/cb', null, 7, 5));
        self::assertSame('pre-client', $this->registrar(false)->clientFor($this->server('pre-client'), $this->metadata(), 'https://typo3.example.com/cb', null, 7, 5)->clientId, 'a public client needs one secret at most');
    }

    #[Test]
    public function withoutARegistrationEndpointAndWithoutAPreRegisteredClientTheConnectionStops(): void
    {
        $this->expectReason('registration_unavailable', fn (): mixed => $this->registrar()->clientFor($this->server(), $this->metadata(''), 'https://typo3.example.com/cb', null, 7, 5));
    }

    #[Test]
    public function aClientAuthenticationMethodThisClientCannotUseIsRefused(): void
    {
        $this->wire->json(self::REGISTER, ['client_id' => 'issued', 'client_secret' => 'shh', 'token_endpoint_auth_method' => 'client_secret_basic'], 201);

        $this->expectReason('client_auth_unsupported', fn (): mixed => $this->registrar()->clientFor($this->server(), $this->metadata(), 'https://typo3.example.com/cb', null, 7, 5));
        self::assertSame([], $this->vaultSecrets);
    }

    private function tokenClient(): McpOAuthTokenClient
    {
        $factory = $this->hostFactory();

        return new McpOAuthTokenClient(new McpOAuthHttp($this->inMemoryVault($this->wire, $factory), $factory));
    }

    private function registrar(?bool $twoSecrets = null): McpOAuthRegistrar
    {
        $factory = $this->hostFactory();
        $vault   = $this->inMemoryVault($this->wire, $factory);

        return new McpOAuthRegistrar(new McpOAuthHttp($vault, $factory, $twoSecrets), new McpOAuthSecrets($vault));
    }

    private function metadata(string $registration = self::REGISTER): McpOAuthMetadata
    {
        return new McpOAuthMetadata('https://mcp.example.com/mcp', 'https://auth.example.com', 'https://auth.example.com/authorize', self::TOKEN, $registration, true, '', []);
    }

    private function grant(string $source, string $clientId, string $issuer, string $secret = '', string $refresh = 'r'): McpOAuthGrant
    {
        return new McpOAuthGrant(1, $issuer, self::TOKEN, 'https://mcp.example.com/mcp', 'https://typo3.example.com/cb', $clientId, $secret, $source, 'a', $refresh, 0, '', McpOAuthGrant::STATUS_CONNECTED, 7, 0);
    }

    private function pending(): McpOAuthPendingAuthorization
    {
        return new McpOAuthPendingAuthorization(1, 'state', 'verifier', 'https://auth.example.com', true, self::TOKEN, 'https://mcp.example.com/mcp', 'https://typo3.example.com/cb', 'client', '', 'dynamic', false, '', 0);
    }

    private function server(string $clientId = '', string $secret = ''): McpServerRecord
    {
        return new McpServerRecord(
            uid: 1,
            pid: 0,
            identifier: 'secured',
            name: 'Secured',
            description: '',
            url: 'https://mcp.example.com/mcp',
            authCredential: '',
            authPlacement: 'bearer',
            authHeaderName: '',
            dataClass: 'publicContent',
            requiresApproval: '1',
            enabled: true,
            importStatus: '',
            importError: '',
            lastImported: 0,
            toolCount: 0,
            lastContact: 0,
            lastLatencyMs: 0,
            tstamp: 0,
            crdate: 0,
            authMode: 'oauth',
            oauthClientId: $clientId,
            oauthClientSecret: $secret,
        );
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectReason(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (McpOAuthException $exception) {
            self::assertSame($reason, $exception->reason());

            return;
        }

        self::fail('Expected the connection to stop with ' . $reason . '.');
    }
}
