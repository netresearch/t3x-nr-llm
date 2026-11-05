<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;

/**
 * Chooses the OAuth client for one connection, in the order the MCP
 * specification (revision 2026-07-28, "Client Registration") recommends for
 * the mechanisms this extension supports (ADR-259):
 *
 * 1. the client pre-registered on the server record;
 * 2. a client registered dynamically earlier at the same issuer for the same
 *    redirect URI;
 * 3. a new Dynamic Client Registration (RFC 7591), if the authorization
 *    server offers it.
 *
 * Client ID Metadata Documents are not among them; ADR-259 says why.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthRegistrar
{
    public const CLIENT_NAME = 'TYPO3 nr_llm MCP client';

    public function __construct(
        private McpOAuthHttp $http,
        private McpOAuthSecrets $secrets,
    ) {}

    /**
     * A client with a secret refreshes with two Vault secrets in one form.
     * Without that capability the connection would work until the first
     * refresh and then fail for good, so it does not start.
     *
     * @throws McpOAuthException
     */
    public function clientFor(
        McpServerRecord $server,
        McpOAuthMetadata $metadata,
        string $redirectUri,
        ?McpOAuthGrant $existing,
        int $owner,
        int $timeoutSeconds,
    ): McpOAuthClientRegistration {
        $client = $this->choose($server, $metadata, $redirectUri, $existing, $owner, $timeoutSeconds);
        if ($client->clientSecretCredential !== '' && !$this->http->canSendTwoSecrets()) {
            if ($client->created) {
                $this->secrets->forget($client->clientSecretCredential);
            }

            throw McpOAuthException::because('vault_capability_missing');
        }

        return $client;
    }

    /**
     * @throws McpOAuthException
     */
    private function choose(
        McpServerRecord $server,
        McpOAuthMetadata $metadata,
        string $redirectUri,
        ?McpOAuthGrant $existing,
        int $owner,
        int $timeoutSeconds,
    ): McpOAuthClientRegistration {
        if ($server->oauthClientId !== '') {
            // MCP "Authorization Server Binding": a pre-registered client
            // belongs to the issuer it was first used with. When the server
            // names another one, say so rather than try the credentials there.
            if ($existing instanceof McpOAuthGrant && $existing->clientSource === McpOAuthGrant::SOURCE_PRE && $existing->clientId === $server->oauthClientId && $existing->issuer !== $metadata->issuer) {
                throw McpOAuthException::because('issuer_changed');
            }

            return new McpOAuthClientRegistration(
                $server->oauthClientId,
                $server->oauthClientSecret,
                McpOAuthGrant::SOURCE_PRE,
                $metadata->issuer,
                $redirectUri,
                false,
            );
        }

        // A grant the authorization server refused may have died with its
        // client (`invalid_client`, an expired or deleted registration), so a
        // reconnect after a refusal registers afresh instead of reusing it.
        if ($existing instanceof McpOAuthGrant
            && $existing->status === McpOAuthGrant::STATUS_CONNECTED
            && $existing->clientSource === McpOAuthGrant::SOURCE_DYNAMIC
            && $existing->clientId !== ''
            && $existing->issuer === $metadata->issuer
            && $existing->redirectUri === $redirectUri
        ) {
            return new McpOAuthClientRegistration(
                $existing->clientId,
                $existing->clientSecretCredential,
                McpOAuthGrant::SOURCE_DYNAMIC,
                $existing->issuer,
                $redirectUri,
                false,
            );
        }

        if ($metadata->registrationEndpoint === '') {
            throw McpOAuthException::because('registration_unavailable');
        }

        return $this->register($server, $metadata, $redirectUri, $owner, $timeoutSeconds);
    }

    /**
     * RFC 7591 section 3.1. `application_type` is `web` (MCP: a remote
     * browser-based application served from a non-local host), the method is
     * `none` because this client keeps no secret it was not given.
     *
     * @throws McpOAuthException
     */
    private function register(
        McpServerRecord $server,
        McpOAuthMetadata $metadata,
        string $redirectUri,
        int $owner,
        int $timeoutSeconds,
    ): McpOAuthClientRegistration {
        $response = $this->http->postJson(
            $metadata->registrationEndpoint,
            [
                'client_name'                => self::CLIENT_NAME,
                'redirect_uris'              => [$redirectUri],
                'grant_types'                => ['authorization_code', 'refresh_token'],
                'response_types'             => ['code'],
                'token_endpoint_auth_method' => 'none',
                'application_type'           => 'web',
            ],
            $timeoutSeconds,
        );

        if (!$response->isSuccess()) {
            throw McpOAuthException::because('registration_refused');
        }

        $body     = $response->body;
        $client   = McpOAuthHttp::jsonObject($response, 'registration_invalid');
        $clientId = $client['client_id'] ?? null;
        if (!is_string($clientId) || $clientId === '' || strlen($clientId) > 255 || preg_match('/\A[\x21-\x7E]+\z/D', $clientId) !== 1) {
            throw McpOAuthException::because('registration_invalid');
        }

        $method = $client['token_endpoint_auth_method'] ?? null;
        $secret = $client['client_secret'] ?? null;
        try {
            if ($method !== null && !in_array($method, ['none', 'client_secret_post'], true)) {
                throw McpOAuthException::because('client_auth_unsupported');
            }

            // A secret issued for `none` is not one this client may use; a
            // secret without a stated method is used as `client_secret_post`.
            $reference = '';
            if (is_string($secret) && $secret !== '' && $method !== 'none') {
                $reference = $this->secrets->store($secret, $owner, $server->identifier, 'client_secret');
            }
        } finally {
            sodium_memzero($body);
        }

        return new McpOAuthClientRegistration(
            $clientId,
            $reference,
            McpOAuthGrant::SOURCE_DYNAMIC,
            $metadata->issuer,
            $redirectUri,
            true,
        );
    }
}
