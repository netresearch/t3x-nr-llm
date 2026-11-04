<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Throwable;
use TYPO3\CMS\Core\Context\Context;

/**
 * Hands out the access token of an OAuth-connected server, refreshed when it
 * is about to expire (ADR-259).
 *
 * Only one process refreshes at a time, and it reads the grant again once it
 * may. Two processes that both present the same refresh token would have the
 * second use treated as a replay, and an authorization server may revoke the
 * whole grant for that (OAuth 2.1 section 4.3.1). The exclusion is a lease on
 * the grant row rather than a TYPO3 lock: the locking strategies core ships
 * hold only on one host, and web containers and queue workers need not share
 * one.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthTokenStore
{
    /** Refresh this many seconds before the stated expiry. */
    public const REFRESH_MARGIN_SECONDS = 60;

    /** How long a lease holds if its holder dies mid-refresh. */
    public const LEASE_SECONDS = 30;

    /** How often a process waits for another one's refresh, a quarter second each. */
    private const LEASE_WAITS = 20;

    /** @var Closure(): void */
    private Closure $pause;

    /**
     * @param (Closure(): void)|null $pause how to wait between two looks at a
     *                                      lease another process holds; a test
     *                                      seam, a quarter second by default
     */
    public function __construct(
        private McpOAuthGrantRepository $grants,
        private McpOAuthTokenClient $tokens,
        private McpOAuthSecrets $secrets,
        private Context $context,
        ?Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (): void {
            usleep(250000);
        };
    }

    /**
     * The Vault reference of a usable access token for this server.
     *
     * @throws McpTransportException
     */
    public function accessCredential(McpServerRecord $server, int $timeoutSeconds): string
    {
        $grant = $this->usableGrant($server);
        if (!$this->needsRefresh($grant, $this->now())) {
            return $grant->accessCredential;
        }

        if ($grant->refreshCredential === '') {
            // Expired and nothing to renew it with: the server named a lifetime
            // and gave no refresh token, so only a new authorization helps.
            if ($this->now() >= $grant->expiresAt) {
                throw McpTransportException::forOAuthFailure($server->identifier, 'token_expired');
            }

            return $grant->accessCredential;
        }

        return $this->refreshExclusively($server, $timeoutSeconds);
    }

    private function needsRefresh(McpOAuthGrant $grant, int $now): bool
    {
        // 0: the server named no lifetime. Nothing to schedule a refresh by;
        // the token is used until the server refuses it.
        return $grant->expiresAt > 0 && $grant->expiresAt - $now <= self::REFRESH_MARGIN_SECONDS;
    }

    /**
     * @throws McpTransportException
     */
    private function refreshExclusively(McpServerRecord $server, int $timeoutSeconds): string
    {
        for ($attempt = 0; $attempt <= self::LEASE_WAITS; ++$attempt) {
            if ($attempt > 0) {
                ($this->pause)();
            }

            // Another process may have refreshed while this one waited.
            $grant = $this->usableGrant($server);
            if (!$this->needsRefresh($grant, $this->now())) {
                return $grant->accessCredential;
            }

            if ($this->grants->acquireLease($server->uid, $this->now(), self::LEASE_SECONDS)) {
                return $this->refreshUnderLease($server, $timeoutSeconds);
            }
        }

        throw McpTransportException::forOAuthFailure($server->identifier, 'refresh_in_progress');
    }

    /**
     * @throws McpTransportException
     */
    private function refreshUnderLease(McpServerRecord $server, int $timeoutSeconds): string
    {
        try {
            // Read once more under the lease: a refresh that finished between
            // the look above and taking the lease is not repeated.
            $grant = $this->usableGrant($server);
            if (!$this->needsRefresh($grant, $this->now())) {
                return $grant->accessCredential;
            }

            return $this->refresh($server, $grant, $timeoutSeconds);
        } catch (McpTransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw McpTransportException::forOAuthFailure($server->identifier, 'refresh_failed');
        } finally {
            try {
                $this->grants->releaseLease($server->uid);
            } catch (Throwable) {
                // The lease runs out on its own; the outcome above stands.
            }
        }
    }

    /**
     * @throws McpTransportException
     */
    private function refresh(McpServerRecord $server, McpOAuthGrant $grant, int $timeoutSeconds): string
    {
        $started = $this->now();
        try {
            $response = $this->tokens->refresh($grant, $timeoutSeconds);
        } catch (McpOAuthException $exception) {
            if ($exception->reason() === 'reconnect_required') {
                $this->grants->markReconnect($server->uid, $started);
            }

            throw McpTransportException::forOAuthFailure($server->identifier, $exception->reason());
        }

        try {
            // The refresh token first. The authorization server has already
            // retired the old one (OAuth 2.1 section 4.3.1), so the new one is
            // the value that must not be lost; a missing access token is
            // recovered by the next refresh.
            if ($response->refreshToken !== '') {
                $this->secrets->rotate($grant->refreshCredential, $response->refreshToken);
            }

            $this->secrets->rotate($grant->accessCredential, $response->accessToken);
        } catch (McpOAuthException $exception) {
            throw McpTransportException::forOAuthFailure($server->identifier, $exception->reason());
        } finally {
            $response->forget();
        }

        $this->grants->recordRefresh(
            $server->uid,
            $response->expiresIn > 0 ? $started + $response->expiresIn : 0,
            $response->scope !== '' ? $response->scope : $grant->scope,
            $started,
        );

        return $grant->accessCredential;
    }

    /**
     * The server's grant, if it may be used for this server as it is now
     * configured.
     *
     * The token was issued for the resource the grant names. A record whose
     * URL was changed afterwards points somewhere the token was not issued
     * for, and sending it there is what MCP "Token Handling" forbids — so the
     * grant is refused until the server is connected again.
     *
     * @throws McpTransportException
     */
    private function usableGrant(McpServerRecord $server): McpOAuthGrant
    {
        $grant = $this->grants->findByServer($server->uid);
        if (!$grant instanceof McpOAuthGrant) {
            throw McpTransportException::forOAuthFailure($server->identifier, 'not_connected');
        }

        if (!$grant->isConnected() || !McpOAuthDiscovery::resourceCovers($grant->resource, $server->url)) {
            throw McpTransportException::forOAuthFailure($server->identifier, 'reconnect_required');
        }

        return $grant;
    }

    private function now(): int
    {
        $now = $this->context->getPropertyFromAspect('date', 'timestamp');

        return is_int($now) ? $now : time();
    }
}
