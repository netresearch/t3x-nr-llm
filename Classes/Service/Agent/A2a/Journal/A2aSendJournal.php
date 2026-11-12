<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Journal;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aIntentConflictException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aJournalException;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The send-intent journal of the A2A client, `tx_nrllm_a2a_send` (spec 051
 * A2A-C4 to A2A-C7).
 *
 * - {@see self::begin()} commits the intent before any network call. It
 *   refuses to run inside an open transaction, because a row that a later
 *   rollback could take back would not have been committed before the send.
 * - Identity is the database's unique key on (owner, message id), not a
 *   lookup followed by an insert: of two concurrent identical intents exactly
 *   one row exists, and the other caller reads it back. The same owner,
 *   message id and digest name the same local operation; the same message id
 *   with another digest is refused (A2A-C5).
 * - Each intent settles once: every outcome is a conditional update on
 *   `state = 'intended'`, so a second outcome for the same row fails rather
 *   than overwriting the first (A2A-C6).
 * - Every read takes the owner, so one local actor never sees another's
 *   handle (A2A-C7). Identifiers compare byte for byte, whatever the
 *   column's collation: of the rows the database returns, only an exact
 *   match counts.
 * - A remote id longer than its column is not stored cut short: the intent
 *   settles as an unknown outcome (`REMOTE_ID_TOO_LONG`), never resent.
 * - Error messages are stored sanitised (ErrorMessageSanitizerTrait), so a
 *   credential in a URL does not reach the table.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendJournal
{
    use ErrorMessageSanitizerTrait;

    public const TABLE = 'tx_nrllm_a2a_send';

    /** The width of `remote_task_id` and `remote_context_id`. */
    private const REMOTE_ID_MAX = 255;

    /** The width of `last_remote_state`. */
    private const REMOTE_STATE_MAX = 64;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * The intent for this owner and message id: a new committed row, or the
     * existing one when the same request was journalled before.
     *
     * @throws A2aIntentConflictException when the message id was used for another request
     * @throws A2aJournalException        when no owner is given or a transaction is open
     */
    public function begin(int $ownerUid, int $agentUid, int $runUid, string $messageId, string $requestSha256): A2aSendBegin
    {
        if ($ownerUid < 1) {
            throw A2aJournalException::noOwner();
        }

        $connection = $this->connection();
        if ($connection->isTransactionActive()) {
            throw A2aJournalException::insideTransaction();
        }

        $now = time();
        try {
            $connection->insert(self::TABLE, [
                'owner_uid'      => $ownerUid,
                'agent_uid'      => $agentUid,
                'run_uid'        => $runUid,
                'message_id'     => $messageId,
                'request_sha256' => $requestSha256,
                'state'          => A2aSendState::INTENDED->value,
                'crdate'         => $now,
                'tstamp'         => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->findByMessageId($ownerUid, $messageId);
            if (!$existing instanceof A2aSendIntent) {
                throw A2aJournalException::unreadable(0);
            }

            if (!hash_equals($existing->requestSha256, $requestSha256) || $existing->agentUid !== $agentUid) {
                throw A2aIntentConflictException::differentPayload($messageId);
            }

            return new A2aSendBegin($existing, false);
        }

        $uid    = (int) $connection->lastInsertId();
        $intent = $this->findForOwner($ownerUid, $uid);
        if (!$intent instanceof A2aSendIntent) {
            throw A2aJournalException::unreadable($uid);
        }

        return new A2aSendBegin($intent, true);
    }

    /**
     * A task came back. Its ids are stored at once, so the handle exists
     * before the caller does anything else with the response (A2A-C6).
     *
     * @throws A2aJournalException
     */
    public function accept(A2aSendIntent $intent, string $remoteTaskId, string $remoteContextId, string $remoteState): A2aSendIntent
    {
        if (strlen($remoteTaskId) > self::REMOTE_ID_MAX || strlen($remoteContextId) > self::REMOTE_ID_MAX) {
            return $this->idTooLong($intent);
        }

        return $this->settle($intent, A2aSendState::ACCEPTED, [
            'remote_task_id'    => $remoteTaskId,
            'remote_context_id' => $remoteContextId,
            'last_remote_state' => $this->state($remoteState),
        ]);
    }

    /**
     * @throws A2aJournalException
     */
    public function answer(A2aSendIntent $intent, string $remoteContextId): A2aSendIntent
    {
        if (strlen($remoteContextId) > self::REMOTE_ID_MAX) {
            return $this->idTooLong($intent);
        }

        return $this->settle($intent, A2aSendState::ANSWERED, ['remote_context_id' => $remoteContextId]);
    }

    /**
     * A JSON-RPC error (`$code` set) or a local refusal (`$code` null).
     *
     * @throws A2aJournalException
     */
    public function refuse(A2aSendIntent $intent, ?int $code, string $reason, string $message): A2aSendIntent
    {
        return $this->settle($intent, A2aSendState::REFUSED, [
            'error_code'    => $code,
            'error_reason'  => mb_substr($reason, 0, 255),
            'error_message' => mb_substr($this->sanitizeErrorMessage($message), 0, 1024),
        ]);
    }

    /**
     * The request may have reached the remote and no response was read. Final:
     * nothing sends this intent again (A2A-C6).
     *
     * @throws A2aJournalException
     */
    public function markOutcomeUnknown(A2aSendIntent $intent, string $reason, string $message): A2aSendIntent
    {
        return $this->settle($intent, A2aSendState::OUTCOME_UNKNOWN, [
            'error_reason'  => mb_substr($reason, 0, 255),
            'error_message' => mb_substr($this->sanitizeErrorMessage($message), 0, 1024),
        ]);
    }

    /**
     * The remote task's latest state as the owner last read it.
     */
    public function recordRemoteState(A2aSendIntent $intent, string $remoteState): void
    {
        $this->connection()->update(
            self::TABLE,
            ['last_remote_state' => $this->state($remoteState), 'tstamp' => time()],
            ['uid' => $intent->uid, 'owner_uid' => $intent->ownerUid],
        );
    }

    /**
     * Count request and response bytes against an intent (spec 051 A2A-R7).
     */
    public function addTransferredBytes(A2aSendIntent $intent, int $bytes): void
    {
        if ($bytes < 1) {
            return;
        }

        $connection = $this->connection();
        $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(self::TABLE) . ' SET transferred_bytes = transferred_bytes + ? WHERE uid = ? AND owner_uid = ?',
            [$bytes, $intent->uid, $intent->ownerUid],
            [Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT],
        );
    }

    /**
     * When the poller looks at the remote task of this intent next (A2A-R10).
     */
    public function schedulePoll(A2aSendIntent $intent, int $nextPollAt, int $interval): void
    {
        $this->connection()->update(
            self::TABLE,
            ['next_poll_at' => $nextPollAt, 'poll_interval' => $interval, 'tstamp' => time()],
            ['uid' => $intent->uid, 'owner_uid' => $intent->ownerUid],
        );
    }

    /**
     * What the remote answered to the one CancelTask sent for this intent
     * (A2A-R12): its task state, or the reason the call failed. Nothing is
     * polled after it.
     */
    public function recordCancelOutcome(A2aSendIntent $intent, string $outcome): void
    {
        $this->connection()->update(
            self::TABLE,
            ['cancel_outcome' => mb_substr($outcome, 0, 255), 'next_poll_at' => null, 'tstamp' => time()],
            ['uid' => $intent->uid, 'owner_uid' => $intent->ownerUid],
        );
    }

    /**
     * How many sends one run made to one agent and how many bytes all of the
     * run's A2A calls moved (A2A-R7). Read from the journal, so the counts
     * survive every suspension and resume, and they count only what nr-llm
     * itself sent.
     *
     * @return array{delegations: int, transferredBytes: int}
     */
    public function runTotals(int $runUid, int $agentUid): array
    {
        if ($runUid < 1) {
            return ['delegations' => 0, 'transferredBytes' => 0];
        }

        $connection = $this->connection();
        $row        = $connection->executeQuery(
            'SELECT SUM(CASE WHEN agent_uid = ? THEN 1 ELSE 0 END) AS delegations, SUM(transferred_bytes) AS bytes FROM ' . $connection->quoteIdentifier(self::TABLE) . ' WHERE run_uid = ?',
            [$agentUid, $runUid],
            [Connection::PARAM_INT, Connection::PARAM_INT],
        )->fetchAssociative();

        return [
            'delegations'      => is_array($row) && is_numeric($row['delegations'] ?? null) ? (int) $row['delegations'] : 0,
            'transferredBytes' => is_array($row) && is_numeric($row['bytes'] ?? null) ? (int) $row['bytes'] : 0,
        ];
    }

    /**
     * The intent of a run waiting for it, read by uid: the poller acts for the
     * intent's owner, whom the waiting run's checkpoint names.
     */
    public function findForRun(int $runUid, int $uid): ?A2aSendIntent
    {
        return $runUid < 1 ? null : $this->first(['run_uid' => $runUid, 'uid' => $uid]);
    }

    public function findForOwner(int $ownerUid, int $uid): ?A2aSendIntent
    {
        return $this->first(['owner_uid' => $ownerUid, 'uid' => $uid]);
    }

    public function findByMessageId(int $ownerUid, string $messageId): ?A2aSendIntent
    {
        return $this->first(['owner_uid' => $ownerUid, 'message_id' => $messageId]);
    }

    /**
     * The handle of a remote task of one agent, if this owner created it.
     */
    public function findByRemoteTask(int $ownerUid, int $agentUid, string $remoteTaskId): ?A2aSendIntent
    {
        if ($remoteTaskId === '') {
            return null;
        }

        return $this->first(['owner_uid' => $ownerUid, 'agent_uid' => $agentUid, 'remote_task_id' => $remoteTaskId]);
    }

    /**
     * Which of the given remote task ids this owner holds a handle for.
     *
     * @param list<string> $remoteTaskIds
     *
     * @return list<string>
     */
    public function ownedRemoteTaskIds(int $ownerUid, int $agentUid, array $remoteTaskIds): array
    {
        $remoteTaskIds = array_values(array_filter(array_unique($remoteTaskIds), static fn (string $id): bool => $id !== ''));
        if ($ownerUid < 1 || $remoteTaskIds === []) {
            return [];
        }

        $queryBuilder = $this->connection()->createQueryBuilder();
        $rows         = $queryBuilder
            ->select('remote_task_id')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('owner_uid', $queryBuilder->createNamedParameter($ownerUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('agent_uid', $queryBuilder->createNamedParameter($agentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('remote_task_id', $queryBuilder->createNamedParameter($remoteTaskIds, Connection::PARAM_STR_ARRAY)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        // Byte for byte: a case-insensitive collation may return a stored id
        // that only matches a requested one up to case.
        $stored = array_map(strval(...), array_filter($rows, is_scalar(...)));

        return array_values(array_unique(array_filter($stored, static fn (string $id): bool => in_array($id, $remoteTaskIds, true))));
    }

    /**
     * @param array<string, int|string|null> $fields
     *
     * @throws A2aJournalException
     */
    private function settle(A2aSendIntent $intent, A2aSendState $state, array $fields): A2aSendIntent
    {
        $connection = $this->connection();
        $affected   = $connection->update(
            self::TABLE,
            ['state' => $state->value, 'tstamp' => time()] + $fields,
            ['uid' => $intent->uid, 'owner_uid' => $intent->ownerUid, 'state' => A2aSendState::INTENDED->value],
        );
        if ($affected !== 1) {
            throw A2aJournalException::alreadySettled($intent->uid);
        }

        return $this->findForOwner($intent->ownerUid, $intent->uid) ?? throw A2aJournalException::unreadable($intent->uid);
    }

    /**
     * The remote reported a task or context id wider than the column. Storing
     * it cut short would give the handle a wrong id; the remote task exists,
     * so the intent settles as an unknown outcome and is never resent.
     *
     * @throws A2aJournalException
     */
    private function idTooLong(A2aSendIntent $intent): A2aSendIntent
    {
        return $this->markOutcomeUnknown($intent, 'REMOTE_ID_TOO_LONG', sprintf('the agent returned a task or context id longer than %d bytes, which the journal cannot hold', self::REMOTE_ID_MAX));
    }

    /**
     * The remote state as a display value: a raw state name the column cannot
     * hold is cut to its width.
     */
    private function state(string $remoteState): string
    {
        return mb_strcut($remoteState, 0, self::REMOTE_STATE_MAX);
    }

    /**
     * The first row that matches every criterion exactly. The database
     * narrows the rows; the comparison here is byte for byte, so a
     * case-insensitive collation or trailing spaces cannot make `task-A`
     * match `task-a`.
     *
     * @param array<string, int|string> $criteria
     */
    private function first(array $criteria): ?A2aSendIntent
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $queryBuilder->select('*')->from(self::TABLE);
        foreach ($criteria as $column => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq(
                $column,
                $queryBuilder->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR),
            ));
        }

        $result = $queryBuilder->orderBy('uid')->executeQuery();
        while (($row = $result->fetchAssociative()) !== false) {
            if ($this->matchesExactly($row, $criteria)) {
                return A2aSendIntent::fromRow($row);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, int|string> $criteria
     */
    private function matchesExactly(array $row, array $criteria): bool
    {
        foreach ($criteria as $column => $value) {
            if (is_string($value) && (!is_string($row[$column] ?? null) || $row[$column] !== $value)) {
                return false;
            }
        }

        return true;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
