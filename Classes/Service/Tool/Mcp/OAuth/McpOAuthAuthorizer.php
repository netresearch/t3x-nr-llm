<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Netresearch\NrLlm\Domain\Enum\McpAuthenticationMode;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Throwable;

/**
 * The authorization code flow with PKCE for one MCP server (ADR-259).
 *
 * {@see self::start()} discovers, registers and builds the authorization URL;
 * {@see self::complete()} validates the response, redeems the code and stores
 * the grant. What connects the two calls is the
 * {@see McpOAuthPendingAuthorization} the caller keeps in the backend session.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthAuthorizer
{
    /**
     * Seconds per request of the interactive flow. It runs in an
     * administrator's request, not in an agent run, so no operation deadline
     * applies; each leg is bounded on its own.
     */
    public const REQUEST_TIMEOUT_SECONDS = 15;

    public function __construct(
        private McpOAuthDiscovery $discovery,
        private McpOAuthRegistrar $registrar,
        private McpOAuthTokenClient $tokens,
        private McpOAuthSecrets $secrets,
        private McpOAuthGrantRepository $grants,
    ) {}

    /**
     * @throws McpOAuthException
     */
    public function start(McpServerRecord $server, string $redirectUri, int $owner, int $now): McpOAuthAuthorizationRequest
    {
        if ($server->authenticationMode() !== McpAuthenticationMode::OAUTH) {
            throw McpOAuthException::because('mode_not_oauth');
        }

        $metadata = $this->discovery->discover($server, self::REQUEST_TIMEOUT_SECONDS);
        $client   = $this->registrar->clientFor(
            $server,
            $metadata,
            $redirectUri,
            $this->grants->findByServer($server->uid),
            $owner,
            self::REQUEST_TIMEOUT_SECONDS,
        );

        // A client with a secret refreshes with two Vault secrets in one
        // form. Without that capability the connection would work until the
        // first refresh and then fail for good, so it does not start.
        if ($client->clientSecretCredential !== '' && !McpOAuthHttp::canSendTwoSecrets()) {
            if ($client->created) {
                $this->secrets->forget($client->clientSecretCredential);
            }

            throw McpOAuthException::because('vault_capability_missing');
        }

        $verifier = McpOAuthPkce::verifier();
        $state    = McpOAuthPkce::state();
        $scope    = McpOAuthScope::select($server->oauthScopes, $metadata->suggestedScope, $metadata->authorizationServerScopes);

        $query = [
            'response_type'         => 'code',
            'client_id'             => $client->clientId,
            'redirect_uri'          => $redirectUri,
            'code_challenge'        => McpOAuthPkce::challenge($verifier),
            'code_challenge_method' => 'S256',
            'state'                 => $state,
            'resource'              => $metadata->resource,
        ];
        if ($scope !== '') {
            $query['scope'] = $scope;
        }

        $separator = str_contains($metadata->authorizationEndpoint, '?') ? '&' : '?';

        return new McpOAuthAuthorizationRequest(
            $metadata->authorizationEndpoint . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            new McpOAuthPendingAuthorization(
                serverUid: $server->uid,
                state: $state,
                verifier: $verifier,
                issuer: $metadata->issuer,
                issParameterSupported: $metadata->issParameterSupported,
                tokenEndpoint: $metadata->tokenEndpoint,
                resource: $metadata->resource,
                redirectUri: $redirectUri,
                clientId: $client->clientId,
                clientSecretCredential: $client->clientSecretCredential,
                clientSource: $client->source,
                clientCreated: $client->created,
                scope: $scope,
                createdAt: $now,
            ),
        );
    }

    /**
     * Finish one authorization from the parameters of the callback.
     *
     * The `iss` check comes first and decides whether anything else in the
     * response may be looked at (RFC 9207 section 2.4, MCP "Authorization
     * Response Validation"): a response from the wrong authorization server is
     * not used, not even for its error.
     *
     * @param array<array-key, mixed> $parameters the callback's query
     *
     * @throws McpOAuthException
     */
    public function complete(
        McpServerRecord $server,
        McpOAuthPendingAuthorization $pending,
        array $parameters,
        int $owner,
        int $now,
    ): McpOAuthGrant {
        try {
            if ($pending->serverUid !== $server->uid) {
                throw McpOAuthException::because('state_invalid');
            }

            if ($pending->isExpired($now)) {
                throw McpOAuthException::because('state_expired');
            }

            self::assertIssuer($pending, $parameters['iss'] ?? null);

            if (array_key_exists('error', $parameters)) {
                throw McpOAuthException::because('authorization_denied');
            }

            $code = $parameters['code'] ?? null;
            if (!is_string($code) || $code === '' || strlen($code) > 4096 || preg_match('/\A[\x21-\x7E]+\z/D', $code) !== 1) {
                throw McpOAuthException::because('code_missing');
            }

            $grant = $this->redeem($server, $pending, $code, $owner, $now);
        } catch (Throwable $exception) {
            $this->abandon($pending);

            throw $exception instanceof McpOAuthException ? $exception : McpOAuthException::because('failed');
        }

        return $grant;
    }

    /**
     * A flow that will not be completed takes the client secret it registered
     * with it; nothing else references that secret.
     */
    public function abandon(McpOAuthPendingAuthorization $pending): void
    {
        if ($pending->clientCreated) {
            $this->secrets->forget($pending->clientSecretCredential);
        }
    }

    /**
     * Remove the server's grant and the Vault secrets it references.
     */
    public function disconnect(McpServerRecord $server): void
    {
        $grant = $this->grants->findByServer($server->uid);
        if (!$grant instanceof McpOAuthGrant) {
            return;
        }

        $this->grants->delete($server->uid);
        $this->secrets->forget(
            $grant->accessCredential,
            $grant->refreshCredential,
            $grant->clientSource === McpOAuthGrant::SOURCE_DYNAMIC ? $grant->clientSecretCredential : '',
        );
    }

    /**
     * RFC 9207 section 2.4 as the MCP table states it. The comparison is a
     * plain string comparison: no case folding, no default-port elision, no
     * trailing-slash or percent-encoding normalisation.
     *
     * @throws McpOAuthException
     */
    public static function assertIssuer(McpOAuthPendingAuthorization $pending, mixed $iss): void
    {
        if ($iss === null) {
            if ($pending->issParameterSupported) {
                throw McpOAuthException::because('iss_missing');
            }

            return;
        }

        if (!is_string($iss) || $iss !== $pending->issuer) {
            throw McpOAuthException::because('iss_mismatch');
        }
    }

    /**
     * @throws McpOAuthException
     */
    private function redeem(
        McpServerRecord $server,
        McpOAuthPendingAuthorization $pending,
        string $code,
        int $owner,
        int $now,
    ): McpOAuthGrant {
        $response = $this->tokens->redeemCode($pending, $code, self::REQUEST_TIMEOUT_SECONDS);

        $previous = $this->grants->findByServer($server->uid);
        $access   = '';
        $refresh  = '';
        try {
            $access = $this->secrets->store($response->accessToken, $owner, $server->identifier, 'access_token');
            if ($response->refreshToken !== '') {
                $refresh = $this->secrets->store($response->refreshToken, $owner, $server->identifier, 'refresh_token');
            }

            $grant = new McpOAuthGrant(
                serverUid: $server->uid,
                issuer: $pending->issuer,
                tokenEndpoint: $pending->tokenEndpoint,
                resource: $pending->resource,
                redirectUri: $pending->redirectUri,
                clientId: $pending->clientId,
                clientSecretCredential: $pending->clientSecretCredential,
                clientSource: $pending->clientSource,
                accessCredential: $access,
                refreshCredential: $refresh,
                expiresAt: $response->expiresIn > 0 ? $now + $response->expiresIn : 0,
                scope: $response->scope !== '' ? $response->scope : $pending->scope,
                status: McpOAuthGrant::STATUS_CONNECTED,
                connectedBy: $owner,
                connectedAt: $now,
            );
            $this->grants->save($grant, $now);
        } catch (Throwable $exception) {
            $this->secrets->forget($access, $refresh);

            throw $exception instanceof McpOAuthException ? $exception : McpOAuthException::because('grant_store_failed');
        } finally {
            $response->forget();
        }

        if ($previous instanceof McpOAuthGrant) {
            $this->secrets->forget(
                $previous->accessCredential,
                $previous->refreshCredential,
                $previous->clientSource === McpOAuthGrant::SOURCE_DYNAMIC && $previous->clientSecretCredential !== $grant->clientSecretCredential ? $previous->clientSecretCredential : '',
            );
        }

        return $grant;
    }
}
