<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth;

use Closure;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;

/**
 * An OAuth-protected MCP server and its authorization server, in process (ADR-259).
 *
 * It answers on the wire the way the MCP specification (revision 2026-07-28)
 * describes: 401 with a Bearer challenge, protected resource metadata,
 * RFC 8414 metadata for an issuer with a path, RFC 7591 registration, and a
 * token endpoint that checks the PKCE verifier against the challenge, the
 * redirect URI, the client and the `resource`, and rotates refresh tokens.
 * The browser leg is {@see self::authorize()}: it validates the request the
 * way an authorization server would and returns the callback parameters.
 *
 * It is a fixture, not a reference implementation: anything it does not
 * check is something no test here relies on.
 */
final class OAuthStubServer implements ClientInterface
{
    public const MCP_URL = 'https://mcp.example.com/mcp';

    public const ISSUER = 'https://auth.example.com/tenant';

    public const PRM_URL = 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp';

    public const AS_METADATA_URL = 'https://auth.example.com/.well-known/oauth-authorization-server/tenant';

    /** @var list<array{method: string, url: string, body: string, authorization: string}> */
    public array $requests = [];

    /** @var array<string, mixed> overrides merged into the authorization server metadata */
    public array $metadataOverrides = [];

    /** @var array<string, mixed> overrides merged into the protected resource metadata */
    public array $resourceOverrides = [];

    /** Whether the 401 names the metadata URL; without it the client must probe. */
    public bool $challengeNamesMetadata = true;

    public bool $registrationReturnsSecret = false;

    public int $tokenLifetime = 3600;

    /** Whether token responses carry a refresh token. */
    public bool $issueRefreshToken = true;

    /** The token endpoint answers 503, as during an outage. */
    public bool $tokenEndpointDown = false;

    /** @var (Closure(): void)|null called when the token endpoint is reached */
    public ?Closure $onToken = null;

    /** @var array<string, array{redirectUris: list<string>, secret: string}> */
    public array $clients = [];

    /** @var array<string, array{client: string, challenge: string, redirect: string, resource: string, scope: string}> */
    private array $codes = [];

    public string $currentAccess = '';

    public string $currentRefresh = '';

    private int $issued = 0;

    /** @var list<array<string, string>> every form the token endpoint received */
    public array $tokenForms = [];

    /** @var list<array<string, mixed>> every registration body */
    public array $registrations = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url  = (string) $request->getUri();
        $body = (string) $request->getBody();

        $this->requests[] = [
            'method'        => $request->getMethod(),
            'url'           => $url,
            'body'          => $body,
            'authorization' => $request->getHeaderLine('Authorization'),
        ];

        return match (true) {
            $url === self::MCP_URL => $this->mcp($request, $body),
            $url === self::PRM_URL => $this->json(array_replace([
                'resource'              => self::MCP_URL,
                'authorization_servers' => [self::ISSUER],
                'scopes_supported'      => ['mcp:tools'],
            ], $this->resourceOverrides)),
            $url === self::AS_METADATA_URL => $this->json(array_replace([
                'issuer'                                         => self::ISSUER,
                'authorization_endpoint'                         => 'https://auth.example.com/tenant/authorize',
                'token_endpoint'                                 => 'https://auth.example.com/tenant/token',
                'registration_endpoint'                          => 'https://auth.example.com/tenant/register',
                'code_challenge_methods_supported'               => ['S256'],
                'response_types_supported'                       => ['code'],
                'scopes_supported'                               => ['mcp:tools', 'offline_access'],
                'authorization_response_iss_parameter_supported' => true,
            ], $this->metadataOverrides)),
            $url === 'https://auth.example.com/tenant/register' => $this->register($body),
            $url === 'https://auth.example.com/tenant/token'    => $this->token($body),
            default                                             => new Response(404),
        };
    }

    /**
     * The browser leg: what an authorization server does with the request and
     * where it sends the user back. Throws on anything a real one would refuse.
     *
     * @return array<string, string> the callback's query parameters
     */
    public function authorize(string $authorizationUrl): array
    {
        if (!str_starts_with($authorizationUrl, 'https://auth.example.com/tenant/authorize?')) {
            throw new RuntimeException('Wrong authorization endpoint.', 1730000001);
        }

        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $client = $this->clients[$this->str($query['client_id'] ?? null)] ?? null;
        if ($client === null || !in_array($query['redirect_uri'] ?? null, $client['redirectUris'], true)) {
            throw new RuntimeException('Unknown client or redirect URI.', 1730000002);
        }

        if (($query['response_type'] ?? null) !== 'code' || ($query['code_challenge_method'] ?? null) !== 'S256' || $this->str($query['code_challenge'] ?? null) === '') {
            throw new RuntimeException('Not a PKCE code request.', 1730000003);
        }

        $code               = 'code-' . (++$this->issued);
        $this->codes[$code] = [
            'client'    => $this->str($query['client_id']),
            'challenge' => $this->str($query['code_challenge']),
            'redirect'  => $this->str($query['redirect_uri']),
            'resource'  => $this->str($query['resource'] ?? null),
            'scope'     => $this->str($query['scope'] ?? null),
        ];

        return ['code' => $code, 'state' => $this->str($query['state'] ?? null), 'iss' => self::ISSUER];
    }

    /**
     * Retire the access token before its stated expiry, as a server that
     * rotates keys does; the refresh token stays good.
     */
    public function retireAccessToken(): void
    {
        $this->currentAccess = 'retired';
    }

    /**
     * Revoke every token, as an operator or a password change would.
     */
    public function revoke(): void
    {
        $this->currentAccess  = '';
        $this->currentRefresh = '';
    }

    /**
     * @return list<string> the request methods sent to the MCP endpoint
     */
    public function mcpMethods(): array
    {
        $methods = [];
        foreach ($this->requests as $request) {
            if ($request['url'] === self::MCP_URL) {
                $decoded   = json_decode($request['body'], true);
                $methods[] = is_array($decoded) && is_string($decoded['method'] ?? null) ? $decoded['method'] : '';
            }
        }

        return $methods;
    }

    private function mcp(RequestInterface $request, string $body): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        if ($this->currentAccess === '' || $authorization !== 'Bearer ' . $this->currentAccess) {
            $challenge = 'Bearer scope="mcp:tools"';
            if ($this->challengeNamesMetadata) {
                $challenge = 'Bearer resource_metadata="' . self::PRM_URL . '", scope="mcp:tools"';
            }

            return new Response(401, ['WWW-Authenticate' => $challenge, 'Content-Type' => 'application/json'], '{"error":"unauthorized"}');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            return new Response(202);
        }

        $result = match ($decoded['method'] ?? null) {
            'initialize' => [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => ['tools' => new stdClass()],
                'serverInfo'      => ['name' => 'stub', 'version' => '1'],
            ],
            'tools/list' => ['tools' => [[
                'name'        => 'whoami',
                'description' => 'Says who the token belongs to.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            ]]],
            'tools/call' => ['content' => [['type' => 'text', 'text' => 'token ' . $this->currentAccess]], 'isError' => false],
            default      => ['ok' => true],
        };

        return $this->json(['jsonrpc' => '2.0', 'id' => $decoded['id'], 'result' => $result]);
    }

    private function register(string $body): ResponseInterface
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_array($decoded['redirect_uris'] ?? null)) {
            return $this->json(['error' => 'invalid_client_metadata'], 400);
        }

        $uris = [];
        foreach ($decoded['redirect_uris'] as $uri) {
            if (is_string($uri)) {
                $uris[] = $uri;
            }
        }

        /** @var array<string, mixed> $decoded */
        $this->registrations[]    = $decoded;
        $clientId                 = 'client-' . (count($this->clients) + 1);
        $secret                   = $this->registrationReturnsSecret ? 'registered-secret-' . $clientId : '';
        $this->clients[$clientId] = ['redirectUris' => $uris, 'secret' => $secret];

        $response = ['client_id' => $clientId, 'redirect_uris' => $uris];
        if ($secret !== '') {
            $response['client_secret']              = $secret;
            $response['token_endpoint_auth_method'] = 'client_secret_post';
        } else {
            $response['token_endpoint_auth_method'] = 'none';
        }

        return $this->json($response, 201);
    }

    private function token(string $body): ResponseInterface
    {
        parse_str($body, $form);
        $fields = [];
        foreach ($form as $key => $value) {
            $fields[(string) $key] = $this->str($value);
        }

        $this->tokenForms[] = $fields;
        if ($this->tokenEndpointDown) {
            return $this->json(['error' => 'temporarily_unavailable'], 503);
        }

        if ($this->onToken instanceof Closure) {
            ($this->onToken)();
        }

        $client = $this->clients[$fields['client_id'] ?? ''] ?? null;
        if ($client === null || $client['secret'] !== '' && ($fields['client_secret'] ?? '') !== $client['secret']) {
            return $this->json(['error' => 'invalid_client'], 401);
        }

        if (($fields['resource'] ?? '') !== self::MCP_URL) {
            return $this->json(['error' => 'invalid_target'], 400);
        }

        if (($fields['grant_type'] ?? '') === 'authorization_code') {
            $code = $this->codes[$fields['code'] ?? ''] ?? null;
            unset($this->codes[$fields['code'] ?? '']);
            $expected = rtrim(strtr(base64_encode(hash('sha256', $fields['code_verifier'] ?? '', true)), '+/', '-_'), '=');
            if ($code === null || $code['client'] !== $fields['client_id'] || $code['redirect'] !== ($fields['redirect_uri'] ?? '') || !hash_equals($code['challenge'], $expected) || $code['resource'] !== self::MCP_URL) {
                return $this->json(['error' => 'invalid_grant'], 400);
            }

            return $this->issue($code['scope']);
        }

        if (($fields['grant_type'] ?? '') === 'refresh_token') {
            if ($this->currentRefresh === '' || ($fields['refresh_token'] ?? '') !== $this->currentRefresh) {
                // A replayed rotated refresh token revokes the grant, as
                // OAuth 2.1 section 4.3.1 allows.
                $this->revoke();

                return $this->json(['error' => 'invalid_grant'], 400);
            }

            return $this->issue('mcp:tools offline_access');
        }

        return $this->json(['error' => 'unsupported_grant_type'], 400);
    }

    private function issue(string $scope): ResponseInterface
    {
        ++$this->issued;
        $this->currentAccess  = 'access-' . $this->issued;
        $this->currentRefresh = $this->issueRefreshToken ? 'refresh-' . $this->issued : '';
        $payload              = [
            'access_token' => $this->currentAccess,
            'token_type'   => 'Bearer',
            'expires_in'   => $this->tokenLifetime,
            'scope'        => $scope,
        ];
        if ($this->issueRefreshToken) {
            $payload['refresh_token'] = $this->currentRefresh;
        }

        return $this->json($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
