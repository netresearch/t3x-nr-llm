<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthOperationGuard;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Hands out the access token of an OAuth-connected server, refreshed when it
 * is about to expire or the server refused it (ADR-259).
 *
 * Time is read from a wall clock on every look, not from TYPO3's `date`
 * aspect: a queue worker sets that aspect once when it starts and keeps it for
 * an hour, and a token store that believed it would neither see a token expire
 * nor agree with a web process about whose lease has run out.
 *
 * Only one process refreshes at a time, and it reads the grant again once it
 * may. Two processes that both present the same refresh token would have the
 * second use treated as a replay, and an authorization server may revoke the
 * whole grant for that (OAuth 2.1 section 4.3.1). The exclusion is a lease on
 * the grant row rather than a TYPO3 lock: the locking strategies core ships
 * hold only on one host, and web containers and queue workers need not share
 * one. Every refresh raises the grant's generation, which is how a caller
 * whose token was refused tells whether someone else has replaced it already.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthTokenStore
{
    /** Refresh this many seconds before the stated expiry. */
    public const REFRESH_MARGIN_SECONDS = 60;

    /**
     * The refresh request's own timeout. Not the run's remaining budget and
     * not cancellable: once the request is out, the authorization server may
     * already have retired the old refresh token, and cutting the answer off
     * would lose the new one — for every run against the server, since the
     * grant is shared. A deadline or a cancel ends the run after the new
     * tokens are stored.
     */
    public const REFRESH_TIMEOUT_SECONDS = 15;

    /**
     * What a lease holds beyond the refresh request's timeout: the two Vault
     * writes and the row update that follow it.
     */
    public const LEASE_MARGIN_SECONDS = 15;

    /**
     * How often a process waits for another one's refresh, a quarter second
     * each: as long as the lease can last, so a refresh that takes its whole
     * timeout still ends the wait with its result. The operation's deadline
     * ends a wait that its budget cannot afford.
     */
    private const LEASE_WAITS = (self::REFRESH_TIMEOUT_SECONDS + self::LEASE_MARGIN_SECONDS) * 4;

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
        private ClockInterface $clock,
        ?Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (): void {
            usleep(250000);
        };
    }

    /**
     * A usable access token for this server.
     *
     * `$rejected` is the grant generation of a token the MCP server has just
     * refused (401): that token is refreshed even though its stated lifetime
     * is not over, unless the grant has moved past that generation already.
     *
     * @throws McpTransportException
     */
    public function accessCredential(
        McpServerRecord $server,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation = null,
        ?int $rejected = null,
    ): McpOAuthAccess {
        $grant = $this->usableGrant($server);
        if (!$this->stale($grant, $rejected)) {
            return McpOAuthAccess::of($grant);
        }

        if ($grant->refreshCredential === '') {
            // Nothing to renew it with: only a new authorization helps once
            // the token has expired or been refused.
            if ($rejected !== null || $this->now() >= $grant->expiresAt) {
                throw McpTransportException::forOAuthFailure($server->identifier, $rejected !== null ? 'token_rejected' : 'token_expired');
            }

            return McpOAuthAccess::of($grant);
        }

        return $this->refreshExclusively($server, $deadline, $cancellation, $rejected);
    }

    /**
     * Whether a server refused a token this store could replace. The
     * transport asks before it retries a request once.
     */
    public function canRenew(McpServerRecord $server): bool
    {
        $grant = $this->grants->findByServer($server->uid);

        return $grant instanceof McpOAuthGrant && $grant->isConnected() && $grant->refreshCredential !== '';
    }

    /**
     * Whether this grant's token has to be replaced: the server refused this
     * very generation, or (without a refusal) it is about to expire. A server
     * that named no lifetime (0) is not scheduled; its token is used until it
     * is refused.
     */
    private function stale(McpOAuthGrant $grant, ?int $rejected): bool
    {
        if ($rejected !== null) {
            return $grant->generation === $rejected;
        }

        return $grant->expiresAt > 0 && $grant->expiresAt - $this->now() <= self::REFRESH_MARGIN_SECONDS;
    }

    /**
     * @throws McpTransportException
     */
    private function refreshExclusively(
        McpServerRecord $server,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation,
        ?int $rejected,
    ): McpOAuthAccess {
        for ($attempt = 0; $attempt <= self::LEASE_WAITS; ++$attempt) {
            if ($attempt > 0) {
                ($this->pause)();
            }

            // The wait spends from the operation's budget and stops on a
            // cancel, like every other leg (ADR-170, ADR-190).
            McpAuthOperationGuard::assertAlive($server->identifier, $deadline, $cancellation);

            // Another process may have refreshed while this one waited.
            $grant = $this->usableGrant($server);
            if (!$this->stale($grant, $rejected)) {
                return McpOAuthAccess::of($grant);
            }

            $until = $this->grants->acquireLease($server->uid, $this->now(), self::REFRESH_TIMEOUT_SECONDS + self::LEASE_MARGIN_SECONDS);
            if ($until !== null) {
                $access = $this->refreshUnderLease($server, $rejected, $until);
                // The new tokens are stored; a run cancelled or out of budget
                // in the meantime ends here, and the grant stays good.
                McpAuthOperationGuard::assertAlive($server->identifier, $deadline, $cancellation);

                return $access;
            }

            // Someone else is refreshing. A token that has not expired yet is
            // still good; only a caller holding an expired or refused one
            // has to wait for the new one.
            if ($rejected === null && $grant->expiresAt > $this->now()) {
                return McpOAuthAccess::of($grant);
            }
        }

        throw McpTransportException::forOAuthFailure($server->identifier, 'refresh_in_progress');
    }

    /**
     * @throws McpTransportException
     */
    private function refreshUnderLease(
        McpServerRecord $server,
        ?int $rejected,
        int $until,
    ): McpOAuthAccess {
        try {
            // Read once more under the lease: a refresh that finished between
            // the look above and taking the lease is not repeated.
            $grant = $this->usableGrant($server);
            if (!$this->stale($grant, $rejected)) {
                return McpOAuthAccess::of($grant);
            }

            return $this->refresh($server, $grant, $rejected, $until);
        } catch (McpTransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw McpTransportException::forOAuthFailure($server->identifier, 'refresh_failed');
        } finally {
            try {
                $this->grants->releaseLease($server->uid, $until);
            } catch (Throwable) {
                // The lease runs out on its own; the outcome above stands.
            }
        }
    }

    /**
     * @throws McpTransportException
     */
    private function refresh(
        McpServerRecord $server,
        McpOAuthGrant $grant,
        ?int $rejected,
        int $until,
    ): McpOAuthAccess {
        try {
            $response = $this->tokens->refresh($grant, self::REFRESH_TIMEOUT_SECONDS);
        } catch (McpOAuthException $exception) {
            if ($exception->reason() === 'reconnect_required') {
                $this->grants->markReconnect($grant, $this->now());
            } elseif ($rejected === null && $grant->expiresAt > $this->now()) {
                // A refresh ahead of expiry that failed for a reason a later
                // attempt may get past costs nothing: the current token is
                // still good, and the next call tries again.
                return McpOAuthAccess::of($grant);
            }

            throw McpTransportException::forOAuthFailure($server->identifier, $exception->reason());
        }

        // The lifetime counts from the answer, not from the request.
        $issued = $this->now();
        try {
            $this->storeRotated($server, $grant, $response);
        } finally {
            $response->forget();
        }

        $this->grants->recordRefresh(
            $grant,
            $until,
            $response->expiresIn > 0 ? $issued + $response->expiresIn : 0,
            $response->scope !== '' ? $response->scope : $grant->scope,
            $issued,
        );

        return new McpOAuthAccess($grant->accessCredential, $grant->generation + 1);
    }

    /**
     * Write the new tokens into Vault, the refresh token first.
     *
     * The authorization server has already retired the old refresh token
     * (OAuth 2.1 section 4.3.1), so the new one is the value that must not be
     * lost. If it cannot be stored — a Vault policy that lets this identity
     * read the secret but not rotate it does that on every expiry — the grant
     * is spent, and saying so now beats presenting the retired token on the
     * next call. A missing access token, by contrast, is recovered by the
     * next refresh.
     *
     * @throws McpTransportException
     */
    private function storeRotated(McpServerRecord $server, McpOAuthGrant $grant, McpOAuthTokenResponse $response): void
    {
        if ($response->refreshToken !== '') {
            try {
                $this->secrets->rotate($grant->refreshCredential, $response->refreshToken);
            } catch (McpOAuthException) {
                $this->grants->markReconnect($grant, $this->now());

                throw McpTransportException::forOAuthFailure($server->identifier, 'refresh_token_not_stored');
            }
        }

        try {
            $this->secrets->rotate($grant->accessCredential, $response->accessToken);
        } catch (McpOAuthException $exception) {
            throw McpTransportException::forOAuthFailure($server->identifier, $exception->reason());
        }
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
        if (!$grant instanceof McpOAuthGrant || $grant->status === McpOAuthGrant::STATUS_DISCONNECTED) {
            throw McpTransportException::forOAuthFailure($server->identifier, 'not_connected');
        }

        if (!$grant->isConnected() || !McpOAuthDiscovery::resourceCovers($grant->resource, $server->url)) {
            throw McpTransportException::forOAuthFailure($server->identifier, 'reconnect_required');
        }

        return $grant;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
