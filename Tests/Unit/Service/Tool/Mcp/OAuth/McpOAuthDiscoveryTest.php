<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\OAuth;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthChallenge;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthDiscovery;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthException;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthHttp;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthHttpResponse;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthScope;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\OAuthVaultTrait;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth\RoutedWire;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Discovery as the MCP specification (revision 2026-07-28) orders it (ADR-259).
 */
#[CoversClass(McpOAuthDiscovery::class)]
#[CoversClass(McpOAuthChallenge::class)]
#[CoversClass(McpOAuthHttp::class)]
#[CoversClass(McpOAuthHttpResponse::class)]
#[CoversClass(McpOAuthScope::class)]
final class McpOAuthDiscoveryTest extends AbstractUnitTestCase
{
    use OAuthVaultTrait;

    private const MCP = 'https://mcp.example.com/public/mcp';

    private const ISSUER = 'https://auth.example.com/tenant1';

    private RoutedWire $wire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wire = new RoutedWire();
    }

    #[Test]
    public function theChallengeNamesTheMetadataAndTheScope(): void
    {
        $this->wire
            ->route(self::MCP, new Response(401, ['WWW-Authenticate' => 'Basic realm="x, y", Bearer error="invalid_token", resource_metadata="https://mcp.example.com/meta", scope="files:read files:write"']))
            ->json('https://mcp.example.com/meta', $this->resource())
            ->json('https://auth.example.com/.well-known/oauth-authorization-server/tenant1', $this->authorization());

        $metadata = $this->discovery()->discover($this->server(), 5);

        self::assertSame(self::MCP, $metadata->resource);
        self::assertSame(self::ISSUER, $metadata->issuer);
        self::assertSame('files:read files:write', $metadata->suggestedScope);
        self::assertSame('https://auth.example.com/tenant1/token', $metadata->tokenEndpoint);
        self::assertSame('https://auth.example.com/tenant1/register', $metadata->registrationEndpoint);
        self::assertTrue($metadata->issParameterSupported);
        self::assertSame(
            [
                'POST ' . self::MCP,
                'GET https://mcp.example.com/meta',
                'GET https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
            ],
            $this->wire->urls(),
        );
        // The probe is an MCP request and carries no credential.
        self::assertSame('', $this->wire->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('application/json, text/event-stream', $this->wire->requests[0]->getHeaderLine('Accept'));
    }

    #[Test]
    public function withoutAChallengeUrlThePathInsertedThenTheRootWellKnownIsTried(): void
    {
        $this->wire
            ->route(self::MCP, new Response(401, ['WWW-Authenticate' => 'Bearer']))
            ->json('https://mcp.example.com/.well-known/oauth-protected-resource', $this->resource())
            ->json('https://auth.example.com/.well-known/oauth-authorization-server/tenant1', $this->authorization());

        $metadata = $this->discovery()->discover($this->server(), 5);

        self::assertSame(
            [
                'POST ' . self::MCP,
                'GET https://mcp.example.com/.well-known/oauth-protected-resource/public/mcp',
                'GET https://mcp.example.com/.well-known/oauth-protected-resource',
                'GET https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
            ],
            $this->wire->urls(),
        );
        // No challenge scope: the protected resource's scopes_supported.
        self::assertSame('mcp:a mcp:b', $metadata->suggestedScope);
    }

    #[Test]
    public function aRootDocumentNamingTheOriginIsAcceptedAndItsResourceIsTheOneRequested(): void
    {
        $this->wire
            ->route(self::MCP, new Response(401, ['WWW-Authenticate' => 'Bearer']))
            ->json('https://mcp.example.com/.well-known/oauth-protected-resource', $this->resource(['resource' => 'https://MCP.example.com/']))
            ->json('https://auth.example.com/.well-known/oauth-authorization-server/tenant1', $this->authorization());

        self::assertSame('https://mcp.example.com', $this->discovery()->discover($this->server(), 5)->resource);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function coverage(): iterable
    {
        yield 'same URL' => ['https://mcp.example.com/mcp', 'https://mcp.example.com/mcp', true];
        yield 'origin' => ['https://mcp.example.com', 'https://mcp.example.com/mcp', true];
        yield 'parent path' => ['https://mcp.example.com/a', 'https://mcp.example.com/a/mcp', true];
        yield 'trailing slash on the server' => ['https://mcp.example.com/mcp', 'https://mcp.example.com/mcp/', true];
        yield 'case of scheme and host' => ['HTTPS://MCP.example.com/mcp', 'https://mcp.example.com/mcp', true];
        yield 'sibling sharing a prefix' => ['https://mcp.example.com/mc', 'https://mcp.example.com/mcp', false];
        yield 'child of the server' => ['https://mcp.example.com/mcp/sub', 'https://mcp.example.com/mcp', false];
        yield 'other host' => ['https://evil.example.com/mcp', 'https://mcp.example.com/mcp', false];
        yield 'other port' => ['https://mcp.example.com:8443/mcp', 'https://mcp.example.com/mcp', false];
        yield 'default port written out' => ['https://mcp.example.com:443/mcp', 'https://mcp.example.com/mcp', true];
        yield 'other scheme' => ['http://mcp.example.com/mcp', 'https://mcp.example.com/mcp', false];
        yield 'not a URL' => ['mcp.example.com', 'https://mcp.example.com/mcp', false];
    }

    #[Test]
    #[DataProvider('coverage')]
    public function aResourceCoversItsOwnPathAndTheEndpointsBelowIt(string $resource, string $server, bool $covers): void
    {
        self::assertSame($covers, McpOAuthDiscovery::resourceCovers($resource, $server));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function issuers(): iterable
    {
        yield 'issuer with a path' => [
            'https://auth.example.com/tenant1',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
                'https://auth.example.com/.well-known/openid-configuration/tenant1',
                'https://auth.example.com/tenant1/.well-known/openid-configuration',
            ],
        ];
        yield 'issuer without a path' => [
            'https://auth.example.com',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server',
                'https://auth.example.com/.well-known/openid-configuration',
            ],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('issuers')]
    public function authorizationServerMetadataIsTriedInTheSpecifiedOrder(string $issuer, array $expected): void
    {
        self::assertSame($expected, McpOAuthDiscovery::authorizationServerCandidates($issuer));

        $last = end($expected);
        self::assertIsString($last);
        $this->wire
            ->route(self::MCP, new Response(401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example.com/meta"']))
            ->json('https://mcp.example.com/meta', $this->resource(['authorization_servers' => [$issuer]]))
            ->json($last, $this->authorization(['issuer' => $issuer]));

        self::assertSame($issuer, $this->discovery()->discover($this->server(), 5)->issuer);
        self::assertSame(
            array_map(static fn (string $url): string => 'GET ' . $url, $expected),
            array_slice($this->wire->urls(), 2),
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function refusals(): iterable
    {
        yield 'metadata for another resource' => [['resource' => 'https://other.example.com/mcp'], [], 'resource_mismatch'];
        yield 'metadata for a sibling path' => [['resource' => 'https://mcp.example.com/public/mc'], [], 'resource_mismatch'];
        yield 'no authorization server' => [['authorization_servers' => []], [], 'authorization_server_missing'];
        yield 'plain http authorization server' => [['authorization_servers' => ['http://auth.example.com/tenant1']], [], 'authorization_server_invalid'];
        yield 'issuer that differs from the URL it came from' => [[], ['issuer' => 'https://honest.example.com'], 'issuer_mismatch'];
        yield 'no code_challenge_methods_supported' => [[], ['code_challenge_methods_supported' => null], 'pkce_unsupported'];
        yield 'plain only' => [[], ['code_challenge_methods_supported' => ['plain']], 'pkce_unsupported'];
        yield 'no code response type' => [[], ['response_types_supported' => ['token']], 'code_flow_unsupported'];
        yield 'plain http token endpoint' => [[], ['token_endpoint' => 'http://auth.example.com/token'], 'endpoint_refused'];
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $authorization
     */
    #[Test]
    #[DataProvider('refusals')]
    public function metadataThatBreaksARuleStopsTheConnection(array $resource, array $authorization, string $reason): void
    {
        $this->wire
            ->route(self::MCP, new Response(401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example.com/meta"']))
            ->json('https://mcp.example.com/meta', $this->resource($resource))
            ->json('https://auth.example.com/.well-known/oauth-authorization-server/tenant1', $this->authorization($authorization));

        $this->expectReason($reason, fn (): mixed => $this->discovery()->discover($this->server(), 5));
    }

    #[Test]
    public function aServerThatAnswersWithoutATokenIsNotAnOAuthServer(): void
    {
        $this->wire->json(self::MCP, ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->expectReason('server_not_protected', fn (): mixed => $this->discovery()->discover($this->server(), 5));
    }

    #[Test]
    public function aRefusedHostIsNeverContacted(): void
    {
        $this->hostsAllowed = false;

        $this->expectReason('host_refused', fn (): mixed => $this->discovery()->discover($this->server(), 5));
        self::assertSame([], $this->wire->requests);
    }

    #[Test]
    public function aMetadataUrlInTheChallengeMustBeHttps(): void
    {
        $this->wire->route(self::MCP, new Response(401, ['WWW-Authenticate' => 'Bearer resource_metadata="http://mcp.example.com/meta"']));

        $this->expectReason('endpoint_refused', fn (): mixed => $this->discovery()->discover($this->server(), 5));
        self::assertCount(1, $this->wire->requests);
    }

    #[Test]
    public function theCanonicalUriLowercasesSchemeAndHostAndDropsFragmentAndEmptyPath(): void
    {
        self::assertSame('https://mcp.example.com', McpOAuthDiscovery::canonicalUri('HTTPS://MCP.Example.com/'));
        self::assertSame('https://mcp.example.com:8443/Server/MCP', McpOAuthDiscovery::canonicalUri('https://MCP.example.com:8443/Server/MCP#frag'));
        self::assertSame('https://mcp.example.com/mcp', McpOAuthDiscovery::canonicalUri('https://mcp.example.com:443/mcp'));
    }

    #[Test]
    public function aChallengeParameterInsideAQuotedStringIsNotAParameter(): void
    {
        $challenge = McpOAuthChallenge::fromHeader('Bearer realm="a, scope=evil", scope="x\\"y"');

        self::assertSame('x"y', $challenge->parameter('scope'));
        self::assertSame('a, scope=evil', $challenge->parameter('realm'));
        self::assertSame('', McpOAuthChallenge::fromHeader('Basic scope="x"')->parameter('scope'));
        self::assertSame('y', McpOAuthChallenge::fromHeader('bearer SCOPE=y, Basic scope=z')->parameter('scope'));
        // A token68 credential with `/` and padding before the Bearer challenge.
        self::assertSame('https://mcp.example.com/meta', McpOAuthChallenge::fromHeader('Negotiate YII/x+A==, Bearer resource_metadata="https://mcp.example.com/meta"')->parameter('resource_metadata'));
    }

    #[Test]
    public function scopesAreDeduplicatedAndUnsafeTokensDropped(): void
    {
        self::assertSame('a b', McpOAuthScope::normalize('  a b a "c\\ '));
        self::assertSame('custom offline_access', McpOAuthScope::select('custom', 'suggested', ['offline_access']));
        self::assertSame('suggested', McpOAuthScope::select('', 'suggested', ['other']));
    }

    private function discovery(): McpOAuthDiscovery
    {
        $factory = $this->hostFactory();

        return new McpOAuthDiscovery(new McpOAuthHttp($this->inMemoryVault($this->wire, $factory), $factory));
    }

    private function server(): McpServerRecord
    {
        return new McpServerRecord(
            uid: 1,
            pid: 0,
            identifier: 'secured',
            name: 'Secured',
            description: '',
            url: self::MCP,
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
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function resource(array $overrides = []): array
    {
        return array_replace([
            'resource'              => self::MCP,
            'authorization_servers' => [self::ISSUER],
            'scopes_supported'      => ['mcp:a', 'mcp:b'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function authorization(array $overrides = []): array
    {
        return array_filter(array_replace([
            'issuer'                                         => self::ISSUER,
            'authorization_endpoint'                         => 'https://auth.example.com/tenant1/authorize',
            'token_endpoint'                                 => 'https://auth.example.com/tenant1/token',
            'registration_endpoint'                          => 'https://auth.example.com/tenant1/register',
            'code_challenge_methods_supported'               => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
        ], $overrides), static fn (mixed $value): bool => $value !== null);
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
