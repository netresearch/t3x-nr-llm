<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\OAuth;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Controller\Backend\McpServerController;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpCredentialSessionInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthException;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthPendingAuthorization;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthSessionStore;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\RoutedWire;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * Where the OAuth mode meets the existing transport, the session and the
 * redirect URI rule (ADR-259).
 */
#[CoversClass(McpHttpTransport::class)]
#[CoversClass(McpTransportException::class)]
#[CoversClass(McpOAuthSessionStore::class)]
#[CoversClass(McpServerController::class)]
final class McpOAuthWiringTest extends AbstractUnitTestCase
{
    /**
     * @return iterable<string, array{0: Response, 1: string}>
     */
    public static function refusals(): iterable
    {
        yield '401' => [new Response(401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']), 'token_rejected'];
        yield '403 insufficient scope' => [new Response(403, ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="files:write"']), 'insufficient_scope'];
        yield '403 other' => [new Response(403), 'forbidden'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function aRefusedTokenNamesTheReconnect(Response $response, string $reason): void
    {
        $wire = (new RoutedWire())->route('https://mcp.example.com/mcp', $response);

        try {
            $this->transport($wire)->call($this->server('oauth'), 'tools/list', [], $this->deadline(), null, null, $this->session());
            self::fail('The refusal was not reported.');
        } catch (McpTransportException $exception) {
            self::assertStringContainsString('(' . $reason . ')', $exception->getMessage());
            self::assertStringContainsString('Connect the server again in the MCP module', $exception->getMessage());
        }
    }

    #[Test]
    public function aLegacyServerKeepsItsPlainStatusMessage(): void
    {
        $wire = (new RoutedWire())->route('https://mcp.example.com/mcp', new Response(401));

        $this->expectExceptionMessage('answered with HTTP status 401');

        $this->transport($wire)->call($this->server('legacy'), 'tools/list', [], $this->deadline());
    }

    #[Test]
    public function anOAuthServerIsNeverCalledWithoutItsCredentialSession(): void
    {
        $wire = new RoutedWire();

        try {
            $this->transport($wire)->call($this->server('oauth'), 'tools/list', [], $this->deadline());
            self::fail('An OAuth server was called anonymously.');
        } catch (McpTransportException $exception) {
            self::assertStringContainsString('credential_session_missing', $exception->getMessage());
        }

        self::assertSame([], $wire->requests);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function redirectUris(): iterable
    {
        yield 'https' => ['https://typo3.example.com/typo3/cb', true];
        yield 'http localhost' => ['http://localhost/typo3/cb', true];
        yield 'http loopback' => ['http://127.0.0.1:8080/typo3/cb', true];
        yield 'http elsewhere' => ['http://typo3.example.com/typo3/cb', false];
        yield 'http localhost lookalike' => ['http://localhost.example.com/typo3/cb', false];
    }

    #[Test]
    #[DataProvider('redirectUris')]
    public function aRedirectUriIsHttpsOrLocal(string $uri, bool $accepted): void
    {
        try {
            McpServerController::assertRedirectUri($uri);
            self::assertTrue($accepted);
        } catch (McpOAuthException $exception) {
            self::assertFalse($accepted);
            self::assertSame('redirect_uri_insecure', $exception->reason());
        }
    }

    #[Test]
    public function aStateWorksOnceAndOnlyInTheSessionThatStartedIt(): void
    {
        $store   = new McpOAuthSessionStore();
        $mine    = $this->user();
        $theirs  = $this->user();
        $pending = $this->pending('state-one', 1000);

        $store->remember($mine, $pending, 1000);

        self::assertNull($store->take($theirs, 'state-one'));
        self::assertEquals($pending, $store->take($mine, 'state-one'));
        self::assertNull($store->take($mine, 'state-one'), 'a state is used once');
    }

    #[Test]
    public function expiredFlowsAreDroppedAndAtMostFiveAreKept(): void
    {
        $store = new McpOAuthSessionStore();
        $user  = $this->user();

        $store->remember($user, $this->pending('old', 1000), 1000);
        foreach (range(1, 6) as $i) {
            $store->remember($user, $this->pending('s' . $i, 2000), 2000);
        }

        self::assertNull($store->take($user, 'old'));
        self::assertNull($store->take($user, 's1'));
        self::assertNotNull($store->take($user, 's6'));
    }

    #[Test]
    public function theResultIsShownOnce(): void
    {
        $store = new McpOAuthSessionStore();
        $user  = $this->user();

        $store->rememberResult($user, 3, 'connected');

        self::assertSame(['server' => 3, 'result' => 'connected'], $store->takeResult($user));
        self::assertNull($store->takeResult($user));
    }

    private function user(): BackendUserAuthentication
    {
        /** @var array<string, mixed> $data */
        $data = [];
        $user = self::createStub(BackendUserAuthentication::class);
        $user->method('getSessionData')->willReturnCallback(static function (string $key) use (&$data): mixed {
            return $data[$key] ?? null;
        });
        $user->method('setAndSaveSessionData')->willReturnCallback(static function (string $key, mixed $value) use (&$data): void {
            $data[$key] = $value;
        });

        return $user;
    }

    private function pending(string $state, int $createdAt): McpOAuthPendingAuthorization
    {
        return new McpOAuthPendingAuthorization(1, $state, 'verifier', 'https://auth.example.com', true, 'https://auth.example.com/token', 'https://mcp.example.com/mcp', 'https://typo3.example.com/cb', 'client', '', 'dynamic', false, '', $createdAt);
    }

    private function transport(RoutedWire $wire): McpHttpTransport
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            new SecureHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($wire);

        return $transport;
    }

    private function session(): McpCredentialSessionInterface
    {
        return new class implements McpCredentialSessionInterface {
            public function credentialIdentifier(): string
            {
                return '11111111-1111-7111-8111-111111111111';
            }

            public function close(): void {}
        };
    }

    private function deadline(): McpOperationDeadline
    {
        return McpOperationDeadline::start(new FakeMcpClock(), 20);
    }

    private function server(string $mode): McpServerRecord
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
            authMode: $mode,
        );
    }
}
