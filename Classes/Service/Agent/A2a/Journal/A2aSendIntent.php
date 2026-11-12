<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Journal;

/**
 * One row of the send-intent journal `tx_nrllm_a2a_send` (spec 051 A2A-C4).
 *
 * It is also the local handle of the remote task once one came back: the
 * handle belongs to `$ownerUid`, the local actor whose intent created it
 * (A2A-C7).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendIntent
{
    public function __construct(
        public int $uid,
        public int $ownerUid,
        public int $agentUid,
        public int $runUid,
        public string $messageId,
        public string $requestSha256,
        public A2aSendState $state,
        public string $remoteTaskId = '',
        public string $remoteContextId = '',
        public string $lastRemoteState = '',
        public ?int $errorCode = null,
        public string $errorReason = '',
        public string $errorMessage = '',
        public int $created = 0,
        public int $updated = 0,
        public int $transferredBytes = 0,
        // When the poller looks at the remote task next; null when nothing is scheduled.
        public ?int $nextPollAt = null,
        public int $pollInterval = 0,
        public string $cancelOutcome = '',
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $int    = static fn (string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
        $string = static fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        $code   = $row['error_code'] ?? null;
        $next   = $row['next_poll_at'] ?? null;

        return new self(
            $int('uid'),
            $int('owner_uid'),
            $int('agent_uid'),
            $int('run_uid'),
            $string('message_id'),
            $string('request_sha256'),
            A2aSendState::tryFrom($string('state')) ?? A2aSendState::OUTCOME_UNKNOWN,
            $string('remote_task_id'),
            $string('remote_context_id'),
            $string('last_remote_state'),
            is_numeric($code) ? (int) $code : null,
            $string('error_reason'),
            $string('error_message'),
            $int('crdate'),
            $int('tstamp'),
            $int('transferred_bytes'),
            is_numeric($next) ? (int) $next : null,
            $int('poll_interval'),
            $string('cancel_outcome'),
        );
    }
}
