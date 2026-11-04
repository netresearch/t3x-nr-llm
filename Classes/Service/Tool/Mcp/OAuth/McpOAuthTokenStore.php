<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Hands out the access token of an OAuth-connected server, refreshed when it
 * is about to expire (ADR-259).
 *
 * The refresh runs under a lock per server and reads the grant again once it
 * holds the lock. Without that, two processes that both see an expiring token
 * would both present the same refresh token; an authorization server that
 * rotates refresh tokens treats the second use as a replay and may revoke the
 * whole grant (OAuth 2.1 section 4.3.1).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthTokenStore
{
    /** Refresh this many seconds before the stated expiry. */
    public const REFRESH_MARGIN_SECONDS = 60;

    public function __construct(
        private McpOAuthGrantRepository $grants,
        private McpOAuthTokenClient $tokens,
        private McpOAuthSecrets $secrets,
        private LockFactory $locks,
        private Context $context,
    ) {}

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

        return $this->refreshLocked($server, $timeoutSeconds);
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
    private function refreshLocked(McpServerRecord $server, int $timeoutSeconds): string
    {
        try {
            $lock = $this->locks->createLocker(
                'nrllm_mcp_oauth_' . $server->uid,
                LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE,
            );
        } catch (Throwable) {
            throw McpTransportException::forOAuthFailure($server->identifier, 'lock_unavailable');
        }

        try {
            if (!$lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)) {
                throw McpTransportException::forOAuthFailure($server->identifier, 'lock_unavailable');
            }

            // Another process may have refreshed while this one waited.
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
                $lock->release();
            } catch (Throwable) {
                // The lock expires with the process; the outcome above stands.
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
            $response = $this->tokens->refresh(
                $grant->tokenEndpoint,
                $grant->clientId,
                $grant->clientSecretCredential,
                $grant->refreshCredential,
                $grant->resource,
                $timeoutSeconds,
            );
        } catch (McpOAuthException $exception) {
            if ($exception->reason() === 'reconnect_required') {
                $this->grants->markReconnect($server->uid, $started);
            }

            throw McpTransportException::forOAuthFailure($server->identifier, $exception->reason());
        }

        try {
            $this->secrets->rotate($grant->accessCredential, $response->accessToken);
            // OAuth 2.1 section 4.3.1: a rotated refresh token replaces the old
            // one, which the authorization server no longer accepts.
            if ($response->refreshToken !== '') {
                $this->secrets->rotate($grant->refreshCredential, $response->refreshToken);
            }
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
     * @throws McpTransportException
     */
    private function usableGrant(McpServerRecord $server): McpOAuthGrant
    {
        $grant = $this->grants->findByServer($server->uid);
        if (!$grant instanceof McpOAuthGrant) {
            throw McpTransportException::forOAuthFailure($server->identifier, 'not_connected');
        }

        if (!$grant->isConnected()) {
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
