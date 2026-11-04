<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Keeps OAuth flows in flight, and the outcome of the last one, in the
 * administrator's backend session (ADR-259).
 *
 * The session is server-side and bound to the user who started the flow, so
 * a `state` that arrives in another user's session finds nothing. A flow is
 * taken out when the callback reads it — a state works once — and dropped
 * after {@see McpOAuthPendingAuthorization::LIFETIME_SECONDS}.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class McpOAuthSessionStore
{
    private const PENDING_KEY = 'nrllm_mcp_oauth_pending';

    private const RESULT_KEY = 'nrllm_mcp_oauth_result';

    /** At most this many flows in flight per session. */
    private const MAX_PENDING = 5;

    public function remember(BackendUserAuthentication $user, McpOAuthPendingAuthorization $pending, int $now): void
    {
        $kept = [];
        foreach ($this->pending($user) as $state => $entry) {
            $flow = McpOAuthPendingAuthorization::fromArray($entry);
            if ($flow instanceof McpOAuthPendingAuthorization && !$flow->isExpired($now)) {
                $kept[$state] = $entry;
            }
        }

        $kept[hash('sha256', $pending->state)] = $pending->toArray();
        $user->setAndSaveSessionData(self::PENDING_KEY, array_slice($kept, -self::MAX_PENDING, null, true));
    }

    /**
     * The flow the state belongs to, removed from the session in the same step.
     */
    public function take(BackendUserAuthentication $user, string $state): ?McpOAuthPendingAuthorization
    {
        $pending = $this->pending($user);
        $key     = hash('sha256', $state);
        $entry   = $pending[$key] ?? null;
        unset($pending[$key]);
        $user->setAndSaveSessionData(self::PENDING_KEY, $pending);

        if (!is_array($entry)) {
            return null;
        }

        $flow = McpOAuthPendingAuthorization::fromArray($entry);

        // The key is the hash of the state; the comparison of the stored state
        // itself is the second half, in constant time.
        return $flow instanceof McpOAuthPendingAuthorization && hash_equals($flow->state, $state) ? $flow : null;
    }

    public function rememberResult(BackendUserAuthentication $user, int $serverUid, string $result): void
    {
        $user->setAndSaveSessionData(self::RESULT_KEY, ['server' => $serverUid, 'result' => $result]);
    }

    /**
     * @return array{server: int, result: string}|null
     */
    public function takeResult(BackendUserAuthentication $user): ?array
    {
        $data = $user->getSessionData(self::RESULT_KEY);
        if ($data === null) {
            return null;
        }

        $user->setAndSaveSessionData(self::RESULT_KEY, null);
        if (!is_array($data) || !is_int($data['server'] ?? null) || !is_string($data['result'] ?? null)) {
            return null;
        }

        return ['server' => $data['server'], 'result' => $data['result']];
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    private function pending(BackendUserAuthentication $user): array
    {
        $data = $user->getSessionData(self::PENDING_KEY);
        if (!is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $entries[$key] = $entry;
            }
        }

        return $entries;
    }
}
