<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Client;

use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The push registrations and the callback inbox of the client role (spec 051
 * A2A-PUSH6 to A2A-PUSH8, ADR-285).
 *
 * - One registration per send intent. Its callback credential has the form
 *   `nrpush_<16 hex>_<43 base64url>`: the public part names the row, the
 *   sha256 of the whole credential is stored and compared with
 *   `hash_equals()` (as ADR-280's client tokens). The credential itself is
 *   returned once, to be handed to the remote, and never stored.
 * - A registration accepts callbacks while it is `pending` (the remote has
 *   not answered the create yet), `active`, or `outcome_unknown` (the remote
 *   may hold the config). A `refused` registration accepts none.
 * - A callback body is stored sealed. A look at the remote task deletes the
 *   callbacks received until it began, so the inbox holds only callbacks the
 *   worker has not handled yet, and its bounds bound the rows stored. The
 *   bound per registration is checked under a lock on the registration row,
 *   the bound in total in the same transaction.
 * - Only a registration whose run is running or waits for its remote task,
 *   and whose remote agent record is enabled and not deleted, accepts a
 *   callback. The registration and its inbox are removed when its
 *   dependency resolves (with the remote's result or at the deadline, also
 *   when the run goes on to wait for another delegation) or the run is
 *   cancelled, by the poller's sweep once the run has ended any other way,
 *   and with their run by the run purge.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPushRegistrationStore
{
    public const TABLE = 'tx_nrllm_a2a_push_registration';

    public const INBOX_TABLE = 'tx_nrllm_a2a_push_inbox';

    public const CREDENTIAL_PREFIX = 'nrpush_';

    public const STATE_PENDING = 'pending';

    public const STATE_ACTIVE = 'active';

    public const STATE_OUTCOME_UNKNOWN = 'outcome_unknown';

    public const STATE_REFUSED = 'refused';

    public const STORED = 'stored';

    public const FULL = 'full';

    public const GONE = 'gone';

    /** States in which a callback is accepted. */
    private const OPEN_STATES = [self::STATE_PENDING, self::STATE_ACTIVE, self::STATE_OUTCOME_UNKNOWN];

    private const RUN_TABLE = 'tx_nrllm_agentrun';

    private const AGENT_TABLE = 'tx_nrllm_a2a_agent';

    /**
     * The run statuses in which a registration takes callbacks: running (the
     * delegation tool registers while its segment runs) and waiting for the
     * remote task (ADR-281).
     */
    private const RUN_OPEN = ['running', 'waiting_for_dependency'];

    /** Compared when no registration matched, so both paths hash and compare. */
    private const NO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private ConnectionPool $connectionPool,
        private AgentStateCodec $codec,
    ) {}

    /**
     * The public part of a credential, null when it does not have the form.
     */
    public static function prefixOf(string $credential): ?string
    {
        return preg_match('/^nrpush_([a-f0-9]{16})_[A-Za-z0-9_-]{43}$/', $credential, $match) === 1 ? $match[1] : null;
    }

    /**
     * A pending registration for the remote task of an accepted intent, with
     * a new credential and the config id the create will carry.
     *
     * @return array{uid: int, configId: string, credential: string}
     */
    public function create(A2aSendIntent $intent, int $now): array
    {
        $prefix     = bin2hex(random_bytes(8));
        $credential = self::CREDENTIAL_PREFIX . $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $configId   = 'nrllm-' . bin2hex(random_bytes(12));

        $connection = $this->connection();
        $connection->insert(self::TABLE, [
            'send_uid'          => $intent->uid,
            'run_uid'           => $intent->runUid,
            'agent_uid'         => $intent->agentUid,
            'owner_uid'         => $intent->ownerUid,
            'remote_task_id'    => $intent->remoteTaskId,
            'config_id'         => $configId,
            'credential_prefix' => $prefix,
            'credential_hash'   => hash('sha256', $credential),
            'state'             => self::STATE_PENDING,
            'last_look_at'      => $now,
            'crdate'            => $now,
            'tstamp'            => $now,
        ]);

        return ['uid' => (int) $connection->lastInsertId(), 'configId' => $configId, 'credential' => $credential];
    }

    public function settle(int $uid, string $state, string $remoteConfigId, string $reason): void
    {
        $this->connection()->update(
            self::TABLE,
            ['state' => $state, 'remote_config_id' => mb_substr($remoteConfigId, 0, 255), 'reason' => mb_substr($reason, 0, 255), 'tstamp' => time()],
            ['uid' => $uid],
        );
    }

    /**
     * The uid of the open registration the credential belongs to; null for
     * a malformed, unknown or wrong credential, a closed registration, a run
     * that no longer runs or waits, and a remote agent record that is
     * disabled, deleted or gone.
     */
    public function authenticate(string $credential): ?int
    {
        $prefix = self::prefixOf($credential);
        $row    = $prefix !== null
            ? $this->connection()->select(['uid', 'credential_hash', 'state', 'run_uid', 'agent_uid'], self::TABLE, ['credential_prefix' => $prefix])->fetchAssociative()
            : false;
        $hash  = is_array($row) && is_string($row['credential_hash'] ?? null) ? $row['credential_hash'] : self::NO_HASH;
        $valid = hash_equals($hash, hash('sha256', $credential));

        if (!is_array($row) || !$valid || !in_array($row['state'] ?? null, self::OPEN_STATES, true) || !is_numeric($row['uid'] ?? null)) {
            return null;
        }

        // A run that ended (cancelled, failed, reaped, completed) takes no
        // callback, even before its registration is closed.
        $status = $this->connectionPool->getConnectionForTable(self::RUN_TABLE)
            ->select(['status'], self::RUN_TABLE, ['uid' => is_numeric($row['run_uid'] ?? null) ? (int) $row['run_uid'] : 0])
            ->fetchOne();

        if (!in_array($status, self::RUN_OPEN, true)) {
            return null;
        }

        // Disabling or deleting the remote agent record stops its callbacks
        // too. Read past the restrictions, so the deleted flag is checked
        // here and not left to a default that a query might not apply.
        $builder = $this->connectionPool->getQueryBuilderForTable(self::AGENT_TABLE);
        $builder->getRestrictions()->removeAll();
        $agent = $builder
            ->select('enabled', 'deleted')
            ->from(self::AGENT_TABLE)
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter(is_numeric($row['agent_uid'] ?? null) ? (int) $row['agent_uid'] : 0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        $usable = is_array($agent)
            && is_numeric($agent['enabled'] ?? null) && (int) $agent['enabled'] === 1
            && is_numeric($agent['deleted'] ?? null) && (int) $agent['deleted'] === 0;

        return $usable ? (int) $row['uid'] : null;
    }

    /**
     * Close every registration whose run is neither running nor waiting for
     * its remote task, or is gone: the paths that end a run without the poller (a failure, the
     * reaper, a cancel whose close failed) leave one behind otherwise.
     *
     * @return int the registrations closed
     */
    public function closeEnded(): int
    {
        $builder = $this->connection()->createQueryBuilder();
        $rows    = $builder
            ->select('r.send_uid', 'a.status')
            ->from(self::TABLE, 'r')
            ->leftJoin('r', self::RUN_TABLE, 'a', $builder->expr()->eq('a.uid', $builder->quoteIdentifier('r.run_uid')))
            ->executeQuery()
            ->fetchAllAssociative();

        $closed = 0;
        foreach ($rows as $row) {
            if (!in_array($row['status'] ?? null, self::RUN_OPEN, true) && is_numeric($row['send_uid'] ?? null)) {
                $this->close((int) $row['send_uid']);
                ++$closed;
            }
        }

        return $closed;
    }

    /**
     * Store one callback body, sealed, unless a bound is reached.
     *
     * @return self::STORED|self::FULL|self::GONE
     */
    public function store(int $registrationUid, string $body, int $now, int $maxPerRegistration, int $maxTotal): string
    {
        return $this->connection()->transactional(function (Connection $connection) use ($registrationUid, $body, $now, $maxPerRegistration, $maxTotal): string {
            // The row lock serialises the callbacks of one registration, so
            // two of them cannot both pass the count the other changes.
            $locked = $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier(self::TABLE) . ' SET uid = uid WHERE uid = ?',
                [$registrationUid],
                [Connection::PARAM_INT],
            );
            if ($locked < 1 && !is_numeric($connection->select(['uid'], self::TABLE, ['uid' => $registrationUid])->fetchOne())) {
                return self::GONE;
            }

            if ($this->unreconciled($connection, $registrationUid) >= $maxPerRegistration || $this->unreconciled($connection, null) >= $maxTotal) {
                return self::FULL;
            }

            $connection->insert(self::INBOX_TABLE, [
                'registration_uid' => $registrationUid,
                'received'         => $now,
                'body'             => $this->codec->encode($body, AgentStateCodec::PURPOSE_A2A_PUSH_INBOX),
                'body_bytes'       => strlen($body),
            ]);

            return self::STORED;
        });
    }

    /**
     * Whether the registration of a send intent holds a callback the worker
     * has not handled yet.
     */
    public function hasUnreconciled(int $sendUid): bool
    {
        $uid = $this->registrationOf($sendUid);

        return $uid !== null && $this->unreconciled($this->connection(), $uid) > 0;
    }

    /**
     * When the remote task of a send intent was last read; null without a
     * registration.
     */
    public function lastLookAt(int $sendUid): ?int
    {
        $value = $this->connection()->select(['last_look_at'], self::TABLE, ['send_uid' => $sendUid])->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The worker read the remote task now: every callback received until
     * now is handled and deleted. One received later stays, and brings the
     * next look forward.
     */
    public function looked(int $sendUid, int $now): void
    {
        $uid = $this->registrationOf($sendUid);
        if ($uid === null) {
            return;
        }

        $connection = $this->connection();
        $connection->update(self::TABLE, ['last_look_at' => $now, 'tstamp' => $now], ['uid' => $uid]);

        $builder = $connection->createQueryBuilder();
        $builder
            ->delete(self::INBOX_TABLE)
            ->where(
                $builder->expr()->eq('registration_uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)),
                $builder->expr()->lte('received', $builder->createNamedParameter($now, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * The dependency resolved: the registration and its inbox go. A later
     * callback finds no registration and is refused.
     */
    public function close(int $sendUid): void
    {
        $uid = $this->registrationOf($sendUid);
        if ($uid === null) {
            return;
        }

        $this->connection()->transactional(static function (Connection $connection) use ($uid): void {
            $connection->delete(self::INBOX_TABLE, ['registration_uid' => $uid]);
            $connection->delete(self::TABLE, ['uid' => $uid]);
        });
    }

    private function registrationOf(int $sendUid): ?int
    {
        $uid = $this->connection()->select(['uid'], self::TABLE, ['send_uid' => $sendUid])->fetchOne();

        return is_numeric($uid) ? (int) $uid : null;
    }

    private function unreconciled(Connection $connection, ?int $registrationUid): int
    {
        $builder = $connection->createQueryBuilder();
        $builder->count('uid')->from(self::INBOX_TABLE);
        if ($registrationUid !== null) {
            $builder->where($builder->expr()->eq('registration_uid', $builder->createNamedParameter($registrationUid, Connection::PARAM_INT)));
        }

        $count = $builder->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
