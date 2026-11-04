<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use stdClass;

/**
 * Finds the authorization server of an MCP server, as the MCP specification
 * (revision 2026-07-28, "Authorization Server Discovery") orders it (ADR-259).
 *
 * 1. An MCP request without a token. A protected server answers 401 and may
 *    name its protected resource metadata and the scope it wants in the
 *    `WWW-Authenticate` challenge.
 * 2. The protected resource metadata (RFC 9728): the URL from the challenge,
 *    otherwise the well-known URI with the endpoint's path inserted, then the
 *    one at the root. Its `resource` must be this server.
 * 3. The authorization server metadata of the first listed authorization
 *    server: RFC 8414 first, then OpenID Connect Discovery, with the path of
 *    the issuer inserted or appended in the specified order. Its `issuer` must
 *    be the issuer the URL was built from, and it must support S256.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthDiscovery
{
    public function __construct(private McpOAuthHttp $http) {}

    /**
     * @throws McpOAuthException
     */
    public function discover(McpServerRecord $server, int $timeoutSeconds): McpOAuthMetadata
    {
        $this->http->assertUrl($server->url, 'server_url_refused');
        $resource  = self::canonicalUri($server->url);
        $challenge = $this->probe($server, $timeoutSeconds);

        $protectedResource = $this->protectedResource($server->url, $resource, $challenge, $timeoutSeconds);
        $issuer            = $this->firstAuthorizationServer($protectedResource);
        $authorization     = $this->authorizationServer($issuer, $timeoutSeconds);

        $suggested = $challenge->parameter('scope');
        if ($suggested === '') {
            $suggested = implode(' ', $this->stringList($protectedResource['scopes_supported'] ?? null));
        }

        return new McpOAuthMetadata(
            resource: $resource,
            issuer: $issuer,
            authorizationEndpoint: $this->endpoint($authorization, 'authorization_endpoint', true),
            tokenEndpoint: $this->endpoint($authorization, 'token_endpoint', true),
            registrationEndpoint: $this->endpoint($authorization, 'registration_endpoint', false),
            issParameterSupported: ($authorization['authorization_response_iss_parameter_supported'] ?? false) === true,
            suggestedScope: McpOAuthScope::normalize($suggested),
            authorizationServerScopes: $this->stringList($authorization['scopes_supported'] ?? null),
        );
    }

    /**
     * The canonical URI of the MCP server (RFC 8707 section 2, MCP
     * "Canonical Server URI"): lower-case scheme and host, no fragment, and no
     * trailing slash on an empty path.
     */
    public static function canonicalUri(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $uri = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $uri .= ':' . $parts['port'];
        }

        $path = $parts['path'] ?? '';
        $uri .= $path === '/' ? '' : $path;
        if (isset($parts['query'])) {
            $uri .= '?' . $parts['query'];
        }

        return $uri;
    }

    /**
     * The unauthenticated MCP request. Anything but 401 means this flow has
     * nothing to do: a 2xx is a server that needs no token, anything else a
     * server that is not answering as the specification describes.
     *
     * @throws McpOAuthException
     */
    private function probe(McpServerRecord $server, int $timeoutSeconds): McpOAuthChallenge
    {
        $response = $this->http->postJson(
            $server->url,
            [
                'jsonrpc' => '2.0',
                'id'      => 'nrllm-oauth-probe',
                'method'  => 'initialize',
                'params'  => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities'    => new stdClass(),
                    'clientInfo'      => ['name' => 'nr-llm', 'version' => '1'],
                ],
            ],
            $timeoutSeconds,
            ['Accept' => 'application/json, text/event-stream'],
        );

        if ($response->isSuccess()) {
            throw McpOAuthException::because('server_not_protected');
        }

        if ($response->status !== 401) {
            throw McpOAuthException::because('server_unexpected_status');
        }

        return McpOAuthChallenge::fromHeader($response->wwwAuthenticate);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws McpOAuthException
     */
    private function protectedResource(string $serverUrl, string $resource, McpOAuthChallenge $challenge, int $timeoutSeconds): array
    {
        $named = $challenge->parameter('resource_metadata');
        // When the challenge names the document, that document is the one to
        // use; the well-known fallbacks apply only when it names none.
        $candidates = $named !== '' ? [$named] : self::protectedResourceCandidates($serverUrl);

        foreach ($candidates as $candidate) {
            $response = $this->http->get($candidate, $timeoutSeconds);
            if (!$response->isSuccess()) {
                continue;
            }

            $metadata = McpOAuthHttp::jsonObject($response, 'resource_metadata_invalid');
            $declared = $metadata['resource'] ?? null;
            if (!is_string($declared) || self::canonicalUri($declared) !== $resource) {
                throw McpOAuthException::because('resource_mismatch');
            }

            return $metadata;
        }

        throw McpOAuthException::because('resource_metadata_missing');
    }

    /**
     * @return list<string>
     */
    public static function protectedResourceCandidates(string $serverUrl): array
    {
        $parts  = parse_url($serverUrl);
        $origin = self::origin(is_array($parts) ? $parts : []);
        $path   = is_array($parts) ? rtrim($parts['path'] ?? '', '/') : '';

        $candidates = [];
        if ($path !== '') {
            $candidates[] = $origin . '/.well-known/oauth-protected-resource' . $path;
        }

        $candidates[] = $origin . '/.well-known/oauth-protected-resource';

        return $candidates;
    }

    /**
     * @return list<string>
     */
    public static function authorizationServerCandidates(string $issuer): array
    {
        $parts  = parse_url($issuer);
        $origin = self::origin(is_array($parts) ? $parts : []);
        $path   = is_array($parts) ? rtrim($parts['path'] ?? '', '/') : '';

        if ($path === '') {
            return [
                $origin . '/.well-known/oauth-authorization-server',
                $origin . '/.well-known/openid-configuration',
            ];
        }

        return [
            $origin . '/.well-known/oauth-authorization-server' . $path,
            $origin . '/.well-known/openid-configuration' . $path,
            $origin . $path . '/.well-known/openid-configuration',
        ];
    }

    /**
     * @param array<string, mixed> $protectedResource
     *
     * @throws McpOAuthException
     */
    private function firstAuthorizationServer(array $protectedResource): string
    {
        $servers = $protectedResource['authorization_servers'] ?? null;
        $first   = is_array($servers) && array_is_list($servers) ? ($servers[0] ?? null) : null;
        if (!is_string($first) || $first === '') {
            throw McpOAuthException::because('authorization_server_missing');
        }

        $parts = parse_url($first);
        if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            throw McpOAuthException::because('authorization_server_invalid');
        }

        $this->http->assertUrl($first, 'authorization_server_invalid');

        return $first;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws McpOAuthException
     */
    private function authorizationServer(string $issuer, int $timeoutSeconds): array
    {
        foreach (self::authorizationServerCandidates($issuer) as $candidate) {
            $response = $this->http->get($candidate, $timeoutSeconds);
            if (!$response->isSuccess()) {
                continue;
            }

            $metadata = McpOAuthHttp::jsonObject($response, 'authorization_metadata_invalid');
            // RFC 8414 section 3.3: identical, compared as strings. A document
            // that names another issuer is not used, whatever else it says.
            if (($metadata['issuer'] ?? null) !== $issuer) {
                throw McpOAuthException::because('issuer_mismatch');
            }

            // MCP "Authorization Code Protection": no listed S256, no flow —
            // for OpenID Connect metadata as much as for RFC 8414.
            if (!in_array('S256', $this->stringList($metadata['code_challenge_methods_supported'] ?? null), true)) {
                throw McpOAuthException::because('pkce_unsupported');
            }

            $responseTypes = $metadata['response_types_supported'] ?? null;
            if ($responseTypes !== null && !in_array('code', $this->stringList($responseTypes), true)) {
                throw McpOAuthException::because('code_flow_unsupported');
            }

            return $metadata;
        }

        throw McpOAuthException::because('authorization_metadata_missing');
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @throws McpOAuthException
     */
    private function endpoint(array $metadata, string $key, bool $required): string
    {
        $value = $metadata[$key] ?? null;
        if ($value === null && !$required) {
            return '';
        }

        if (!is_string($value) || $value === '') {
            throw McpOAuthException::because('authorization_metadata_invalid');
        }

        $this->http->assertUrl($value);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parts
     */
    private static function origin(array $parts): string
    {
        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : 'https';
        $host   = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $port   = is_int($parts['port'] ?? null) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }
}
