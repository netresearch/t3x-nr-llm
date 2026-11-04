<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use JsonException;
use SensitiveParameter;

/**
 * The two token requests of an OAuth connection (ADR-259): redeeming an
 * authorization code and refreshing. Both carry `resource` (RFC 8707), and
 * both authenticate the client the same way — public (`none`) with the client
 * ID in the body, or `client_secret_post` with the secret written into the
 * body by Vault.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthTokenClient
{
    private const MAX_TOKEN_BYTES = 8192;

    public function __construct(private McpOAuthHttp $http) {}

    /**
     * @throws McpOAuthException
     */
    public function redeemCode(
        McpOAuthPendingAuthorization $pending,
        #[SensitiveParameter]
        string $code,
        int $timeoutSeconds,
    ): McpOAuthTokenResponse {
        $secrets = $pending->clientSecretCredential !== '' ? ['client_secret' => $pending->clientSecretCredential] : [];

        return $this->request(
            $pending->tokenEndpoint,
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $pending->redirectUri,
                'client_id'     => $pending->clientId,
                'code_verifier' => $pending->verifier,
                'resource'      => $pending->resource,
            ],
            $secrets,
            $timeoutSeconds,
            'token_refused',
        );
    }

    /**
     * A refresh the authorization server refused as a grant (`invalid_grant`,
     * `invalid_client`, `unauthorized_client`) throws `reconnect_required`;
     * any other failure throws `refresh_failed`, so an outage does not cost
     * the operator a reconnect.
     *
     * @throws McpOAuthException
     */
    public function refresh(McpOAuthGrant $grant, int $timeoutSeconds): McpOAuthTokenResponse
    {
        $secrets = ['refresh_token' => $grant->refreshCredential];
        if ($grant->clientSecretCredential !== '') {
            $secrets['client_secret'] = $grant->clientSecretCredential;
        }

        return $this->request(
            $grant->tokenEndpoint,
            [
                'grant_type' => 'refresh_token',
                'client_id'  => $grant->clientId,
                'resource'   => $grant->resource,
            ],
            $secrets,
            $timeoutSeconds,
            'refresh_failed',
        );
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $secrets
     *
     * @throws McpOAuthException
     */
    private function request(
        string $tokenEndpoint,
        array $fields,
        array $secrets,
        int $timeoutSeconds,
        string $failure,
    ): McpOAuthTokenResponse {
        $response = $this->http->postForm($tokenEndpoint, $fields, $secrets, $timeoutSeconds);
        $body     = $response->body;
        try {
            if (!$response->isSuccess()) {
                throw McpOAuthException::because($failure === 'refresh_failed' && $this->grantRefused($response) ? 'reconnect_required' : $failure);
            }

            return $this->validated(McpOAuthHttp::jsonObject($response, $failure));
        } finally {
            sodium_memzero($body);
        }
    }

    /**
     * RFC 6749 section 5.2: the error codes that say the grant or the client
     * itself is no longer good, as opposed to a request the server could not
     * serve right now.
     */
    private function grantRefused(McpOAuthHttpResponse $response): bool
    {
        if ($response->status !== 400 && $response->status !== 401) {
            return false;
        }

        try {
            $decoded = json_decode($response->body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($decoded) && in_array($decoded['error'] ?? null, ['invalid_grant', 'invalid_client', 'unauthorized_client'], true);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws McpOAuthException
     */
    private function validated(array $payload): McpOAuthTokenResponse
    {
        $access = $payload['access_token'] ?? null;
        if (!is_string($access) || !$this->isTokenValue($access)) {
            throw McpOAuthException::because('token_invalid');
        }

        $type = $payload['token_type'] ?? null;
        if (!is_string($type) || strcasecmp($type, 'Bearer') !== 0) {
            throw McpOAuthException::because('token_type_unsupported');
        }

        $refresh = $payload['refresh_token'] ?? '';
        if (!is_string($refresh) || $refresh !== '' && !$this->isTokenValue($refresh)) {
            throw McpOAuthException::because('token_invalid');
        }

        $lifetime = $payload['expires_in'] ?? 0;
        if (is_string($lifetime) && preg_match('/\A\d{1,9}\z/D', $lifetime) === 1) {
            // Some servers send the number as a string; RFC 6749 says number.
            $lifetime = (int) $lifetime;
        }

        if (!is_int($lifetime) || $lifetime < 0) {
            throw McpOAuthException::because('token_invalid');
        }

        $scope = $payload['scope'] ?? '';
        if (!is_string($scope)) {
            throw McpOAuthException::because('token_invalid');
        }

        return new McpOAuthTokenResponse($access, $refresh, $lifetime, McpOAuthScope::normalize($scope));
    }

    /**
     * Printable ASCII, bounded. A token is written into an HTTP header, so a
     * control character or a space in it would be a header injection.
     */
    private function isTokenValue(string $value): bool
    {
        return $value !== '' && strlen($value) <= self::MAX_TOKEN_BYTES && preg_match('/\A[\x21-\x7E]+\z/D', $value) === 1;
    }
}
