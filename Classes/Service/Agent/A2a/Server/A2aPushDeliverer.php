<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\Exception\AgentStateDecryptionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Delivers the push outbox of the server role (spec 051 A2A-PUSH3 to
 * A2A-PUSH5, ADR-285). Runs in the worker only; no request ever calls it.
 *
 * - A job names a config and a task revision, never content. What is sent is
 *   the task as it is now, under the current privacy policy and without
 *   history: a bare `StreamResponse` `{"task": …}`. So only the newest pending
 *   job of a config is sent; older ones are superseded by it.
 * - A job is claimed with a lease before it is sent, so two workers never
 *   send the same job at once; the claim counts the attempt. Before the
 *   attempt the config is read again: a deleted or paused config sends
 *   nothing. The address guard runs inside the transport on every attempt
 *   (A2A-SEC5).
 * - A pass is bounded: it claims no job once its time budget is spent, takes
 *   at most a few jobs per client, and lets the clients take turns, so a
 *   client with many slow receivers does not hold the others back.
 * - 2xx acknowledges. A timeout, a connection that broke after the request
 *   left, 429, 502, 503 and 504 are retried with exponential backoff and
 *   jitter, at most eight attempts and never after the job's deadline. The
 *   backoff and the attempts belong to the config, not to one event: a new
 *   event of a config waiting to retry takes them over. 401
 *   and 403 mark the config `auth_failed`; 404, 410 and a refused address
 *   stop it. A redirect is never followed: it is a failed attempt. Any other
 *   answer fails the job and leaves the config as it is.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPushDeliverer
{
    public const JOB_PENDING = 'pending';

    public const JOB_DELIVERED = 'delivered';

    public const JOB_SUPERSEDED = 'superseded';

    public const JOB_FAILED = 'failed';

    public const JOB_ABANDONED = 'abandoned';

    public const JOB_DROPPED = 'dropped';

    /** How long after its event a job may still be attempted. */
    public const DEADLINE_SECONDS = 86400;

    public const MAX_ATTEMPTS = 8;

    /**
     * Seconds after the start of a pass in which it still claims a job. A pass
     * lasts at most this plus one attempt, so a tick scheduled every minute
     * is not held up by slow receivers.
     */
    public const PASS_BUDGET_SECONDS = 30;

    /** Jobs of one client a pass takes at most, so the other clients get turns. */
    public const MAX_JOBS_PER_CLIENT_PER_PASS = 10;

    private const KEEP_FINISHED_SECONDS = 604800;

    /**
     * How long a claimed job is held. It covers one attempt: the address
     * check with its DNS lookup (bounded by the resolver's own timeouts, not
     * by this class: glibc's defaults of 5 seconds, 2 attempts and at most 3
     * name servers allow about 30 seconds; not measured here) and the request
     * of at most TIMEOUT_SECONDS, with margin. A lookup slower than that lets
     * another worker claim the job again; the attempt is counted at the claim,
     * so the cap on attempts still holds.
     */
    private const LEASE_SECONDS = 120;

    private const TIMEOUT_SECONDS = 15;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const MAX_RESPONSE_BYTES = 65536;

    private const BACKOFF_BASE_SECONDS = 5;

    private const BACKOFF_MAX_SECONDS = 3600;

    private const RETRY_STATUSES = [429, 502, 503, 504];

    private const STOP_STATUSES = [404, 410];

    private const AUTH_STATUSES = [401, 403];

    /** @var Closure(int): int */
    private Closure $jitter;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param (Closure(int): int)|null $jitter the jitter added to a delay, 0 to a quarter of it by default; tests pass a fixed one
     * @param (Closure(): int)|null    $clock  the wall clock, `time()` by default; tests pass one they move
     */
    public function __construct(
        private ConnectionPool $connectionPool,
        private A2aTransportInterface $transport,
        private A2aTaskView $view,
        private AgentStateCodec $codec,
        private A2aUrlGuardFactory $guards,
        private A2aServerSettings $settings,
        private A2aAuthenticator $authenticator,
        private ?LoggerInterface $logger = null,
        ?Closure $jitter = null,
        ?Closure $clock = null,
    ) {
        $this->jitter = $jitter ?? static fn (int $delay): int => random_int(0, max(0, intdiv($delay, 4)));
        $this->clock  = $clock ?? time(...);
    }

    /**
     * Writes one job per active config of the task for this revision. Called
     * by the projector inside the transaction that writes the event, so an
     * event and its jobs commit together (A2A-PUSH3).
     *
     * A config with a pending job is a delivery under way: the new job takes
     * over its attempts and its next attempt, so a failing receiver is not
     * tried again from the start with every event (A2A-PUSH5). While an
     * attempt holds its lease, the new job is not due before the lease ends:
     * the attempt's outcome sets when it goes (a retry's backoff, or at once
     * after a success). That keeps a second worker from sending it in
     * parallel only when the job is written after the attempt's claim; a job
     * written between another worker's selection of due jobs and its claim
     * can still go out alongside the attempt.
     */
    public static function enqueue(Connection $connection, int $taskUid, int $revision, int $now): void
    {
        $outbox = $connection->quoteIdentifier(A2aPushConfigStore::OUTBOX_TABLE);
        $connection->executeStatement(
            'INSERT INTO ' . $outbox
            . ' (config_uid, task_uid, revision, state, attempts, next_attempt, deadline, lease_holder, lease_until, last_status, last_reason, crdate, tstamp)'
            . ' SELECT p.uid, p.task_uid, ?, ?,'
            . ' COALESCE((SELECT MAX(q.attempts) FROM ' . $outbox . ' q WHERE q.config_uid = p.uid AND q.state = ?), 0),'
            . ' COALESCE((SELECT MAX(CASE WHEN q.lease_until > q.next_attempt THEN q.lease_until ELSE q.next_attempt END) FROM ' . $outbox . ' q WHERE q.config_uid = p.uid AND q.state = ?), ?),'
            . ' ?, ?, 0, 0, ?, ?, ?'
            . ' FROM ' . $connection->quoteIdentifier(A2aPushConfigStore::TABLE) . ' p'
            . ' WHERE p.task_uid = ? AND p.state = ?'
            . ' AND EXISTS (SELECT 1 FROM ' . $connection->quoteIdentifier(A2aInboundClientRepository::TABLE) . ' c WHERE c.uid = p.owner_client_uid AND c.enabled = 1 AND c.deleted = 0)',
            [$revision, self::JOB_PENDING, self::JOB_PENDING, self::JOB_PENDING, $now, $now + self::DEADLINE_SECONDS, '', '', $now, $now, $taskUid, A2aPushConfigStore::STATE_ACTIVE],
            [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR],
        );
    }

    /**
     * One pass over the due jobs.
     *
     * @return int deliveries a receiver acknowledged
     */
    public function deliverDue(int $now, int $limit = 50): int
    {
        $started = ($this->clock)();
        // Housekeeping runs whether push is on or not: switching push off
        // stops what is sent, not the removal of old jobs and the end of jobs
        // past their deadline.
        $this->tidy($now);

        if (!$this->settings->pushEnabled()) {
            // The switch is a kill switch: while push is off nothing is sent.
            // Pending jobs are kept and go out if push is switched on again
            // before their deadline; after it they are abandoned unsent.
            return 0;
        }

        $delivered = 0;
        foreach ($this->dueJobs($now, max(1, $limit)) as $uid) {
            if (($this->clock)() - $started >= self::PASS_BUDGET_SECONDS) {
                // The budget is spent: the rest waits for the next tick, so a
                // pass of slow receivers does not hold up the worker.
                break;
            }

            try {
                // The clock is read per attempt: a pass that sends many jobs to
                // slow receivers outlasts a lease taken from the tick's start,
                // and another worker could claim the same job again.
                $delivered += $this->attempt($uid, max($now, ($this->clock)())) ? 1 : 0;
            } catch (Throwable $e) {
                // One job must not stop the others; its lease runs out and it
                // is tried again.
                $this->logger?->error('An A2A push delivery failed unexpectedly', ['job' => $uid, 'exception' => $e]);
            }
        }

        return $delivered;
    }

    /**
     * Removes finished jobs past their retention and abandons pending jobs
     * past their deadline that no attempt holds.
     */
    private function tidy(int $now): void
    {
        $connection = $this->connection();
        $table      = $connection->quoteIdentifier(A2aPushConfigStore::OUTBOX_TABLE);
        // A finished job is kept for a week as the record of what happened,
        // then removed: the outbox does not grow with every event ever sent.
        $connection->executeStatement(
            'DELETE FROM ' . $table . ' WHERE state <> ? AND tstamp < ?',
            [self::JOB_PENDING, $now - self::KEEP_FINISHED_SECONDS],
            [Connection::PARAM_STR, Connection::PARAM_INT],
        );

        $abandoned = $connection->executeStatement(
            'UPDATE ' . $table . ' SET state = ?, last_reason = ?, lease_holder = ?, lease_until = 0, tstamp = ?'
            . ' WHERE state = ? AND deadline < ? AND lease_until < ?',
            [self::JOB_ABANDONED, 'DEADLINE_PASSED', '', $now, self::JOB_PENDING, $now, $now],
            [Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT],
        );
        if ($abandoned > 0) {
            $this->logger?->warning('A2A push jobs were abandoned at their deadline', ['jobs' => $abandoned]);
        }
    }

    /**
     * The jobs of this pass: the newest pending job of each config (the older
     * ones are sent by it, since every delivery carries the task as it is
     * now), at most MAX_JOBS_PER_CLIENT_PER_PASS per client, the clients
     * taking turns in the order their oldest due job waits.
     *
     * @return list<int>
     */
    private function dueJobs(int $now, int $limit): array
    {
        $connection = $this->connection();
        $outbox     = $connection->quoteIdentifier(A2aPushConfigStore::OUTBOX_TABLE);
        // A job whose config is gone has no client; it is still picked, as
        // client 0, so the attempt drops it.
        $due = ' FROM ' . $outbox . ' o LEFT JOIN ' . $connection->quoteIdentifier(A2aPushConfigStore::TABLE) . ' p ON p.uid = o.config_uid'
            . ' WHERE o.state = ? AND o.next_attempt <= ? AND o.lease_until < ?'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $outbox . ' n WHERE n.config_uid = o.config_uid AND n.state = ? AND n.revision > o.revision)';
        $params = [self::JOB_PENDING, $now, $now, self::JOB_PENDING];
        $types  = [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR];

        $clients = $connection->executeQuery(
            'SELECT COALESCE(p.owner_client_uid, 0) AS client, MIN(o.next_attempt) AS first' . $due
            . ' GROUP BY COALESCE(p.owner_client_uid, 0) ORDER BY first, client LIMIT ' . $limit,
            $params,
            $types,
        )->fetchFirstColumn();

        $queues = [];
        foreach ($clients as $client) {
            $queues[] = array_values(array_map(
                $this->int(...),
                $connection->executeQuery(
                    'SELECT o.uid' . $due . ' AND COALESCE(p.owner_client_uid, 0) = ? ORDER BY o.next_attempt, o.uid LIMIT ' . self::MAX_JOBS_PER_CLIENT_PER_PASS,
                    [...$params, $this->int($client)],
                    [...$types, Connection::PARAM_INT],
                )->fetchFirstColumn(),
            ));
        }

        // Round robin: every client's first job, then every client's second.
        $jobs    = [];
        $longest = max([0, ...array_map(count(...), $queues)]);
        for ($turn = 0; $turn < $longest; ++$turn) {
            foreach ($queues as $queue) {
                if (isset($queue[$turn])) {
                    $jobs[] = $queue[$turn];
                }
            }
        }

        return array_slice($jobs, 0, $limit);
    }

    private function attempt(int $jobUid, int $now): bool
    {
        $connection = $this->connection();
        $holder     = Uuid::v4()->toRfc4122();
        // The claim counts the attempt, before anything is sent: an attempt
        // that fails after its request left (an error recording the outcome)
        // still counts, so its lease running out cannot repeat it beyond the
        // cap (A2A-PUSH5).
        $claimed = $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(A2aPushConfigStore::OUTBOX_TABLE)
            . ' SET lease_holder = ?, lease_until = ?, attempts = attempts + 1, tstamp = ? WHERE uid = ? AND state = ? AND lease_until < ?',
            [$holder, $now + self::LEASE_SECONDS, $now, $jobUid, self::JOB_PENDING, $now],
            [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
        );
        if ($claimed !== 1) {
            return false;
        }

        $job = $connection->select(['*'], A2aPushConfigStore::OUTBOX_TABLE, ['uid' => $jobUid])->fetchAssociative();
        if (!is_array($job)) {
            return false;
        }

        // This attempt is counted; an exit that sends nothing records the
        // count before it.
        $attempts = $this->int($job['attempts'] ?? 0);
        $unsent   = max(0, $attempts - 1);
        if ($now > $this->int($job['deadline'] ?? 0)) {
            $this->finish($jobUid, $holder, self::JOB_ABANDONED, 0, 'DEADLINE_PASSED', $unsent, $now);
            $this->logger?->warning('An A2A push was abandoned at its deadline', ['job' => $jobUid]);

            return false;
        }

        if ($attempts > self::MAX_ATTEMPTS) {
            // The attempts of the config are used up, by this job or by the
            // job it took them over from.
            $this->finish($jobUid, $holder, self::JOB_ABANDONED, $this->int($job['last_status'] ?? 0), 'MAX_ATTEMPTS', $unsent, $now);
            $this->supersedeOlder($job, $now);
            $this->logger?->warning('An A2A push was abandoned after its last attempt', ['job' => $jobUid]);

            return false;
        }

        // Read again before every attempt: a config deleted or paused since
        // the job was written sends nothing (A2A-PUSH3, A2A-PUSH5).
        $config = $connection->select(['*'], A2aPushConfigStore::TABLE, ['uid' => $this->int($job['config_uid'] ?? 0)])->fetchAssociative();
        if (!is_array($config) || ($config['state'] ?? '') !== A2aPushConfigStore::STATE_ACTIVE) {
            $this->finish($jobUid, $holder, self::JOB_DROPPED, 0, 'CONFIG_GONE', $unsent, $now);

            return false;
        }

        // A client revoked, disabled or expired since the job was written,
        // whose token was rotated since the config was created, or whose
        // technical user is no longer usable, gets nothing more; its config
        // stops.
        if (!$this->authenticator->mayStillAct($this->int($config['owner_client_uid'] ?? 0), is_string($config['client_token_hash'] ?? null) ? $config['client_token_hash'] : '', $now)) {
            $this->stopConfig($config, A2aPushConfigStore::STATE_STOPPED, 'CLIENT_INACTIVE', $now);
            $this->finish($jobUid, $holder, self::JOB_DROPPED, 0, 'CLIENT_INACTIVE', $unsent, $now);

            return false;
        }

        try {
            $request = $this->request($config);
        } catch (AgentStateDecryptionException) {
            // A credential that cannot be opened (a key that is gone) never
            // will be: the config stops instead of being retried every tick.
            $this->stopConfig($config, A2aPushConfigStore::STATE_STOPPED, 'CREDENTIAL_UNREADABLE', $now);
            $this->finish($jobUid, $holder, self::JOB_FAILED, 0, 'CREDENTIAL_UNREADABLE', $unsent, $now);
            $this->logger?->error('The credential of an A2A push config cannot be read; the config is stopped', ['config' => $config['uid'] ?? 0]);

            return false;
        }

        if ($request === null) {
            $this->finish($jobUid, $holder, self::JOB_DROPPED, 0, 'TASK_GONE', $unsent, $now);

            return false;
        }

        if ($request === false) {
            $this->stopConfig($config, A2aPushConfigStore::STATE_STOPPED, 'NOT_HTTPS', $now);
            $this->finish($jobUid, $holder, self::JOB_FAILED, 0, 'NOT_HTTPS', $attempts, $now);

            return false;
        }

        $result = $this->transport->send($request);

        return $this->outcome($job, $config, $holder, $result, $attempts, $now);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $config
     */
    private function outcome(array $job, array $config, string $holder, A2aTransportResult $result, int $attempts, int $now): bool
    {
        $jobUid = $this->int($job['uid'] ?? 0);
        $status = $result->status;
        $reason = $result->outcome === A2aTransportOutcome::RESPONSE ? 'HTTP_' . $status : $result->reason;

        if ($result->isSuccess()) {
            $this->finish($jobUid, $holder, self::JOB_DELIVERED, $status, $reason, $attempts, $now);
            $this->supersedeOlder($job, $now);
            $this->freshStart($jobUid, $this->int($job['config_uid'] ?? 0), $now);

            return true;
        }

        if ($result->outcome === A2aTransportOutcome::RESPONSE && in_array($status, self::AUTH_STATUSES, true)) {
            $this->stopConfig($config, A2aPushConfigStore::STATE_AUTH_FAILED, $reason, $now);
            $this->finish($jobUid, $holder, self::JOB_FAILED, $status, $reason, $attempts, $now);
            $this->logger?->warning('An A2A push receiver refused the credential; the push config is paused', ['config' => $config['uid'] ?? 0, 'status' => $status]);

            return false;
        }

        if (($result->outcome === A2aTransportOutcome::RESPONSE && in_array($status, self::STOP_STATUSES, true)) || $result->reason === 'ADDRESS_REFUSED') {
            $this->stopConfig($config, A2aPushConfigStore::STATE_STOPPED, $reason, $now);
            $this->finish($jobUid, $holder, self::JOB_FAILED, $status, $reason, $attempts, $now);
            $this->logger?->warning('An A2A push target is gone or not allowed; the push config is stopped', ['config' => $config['uid'] ?? 0, 'reason' => $reason]);

            return false;
        }

        if ($this->retryable($result) && $attempts < self::MAX_ATTEMPTS) {
            $delay = min(self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * (2 ** ($attempts - 1)));
            $next  = $now + $delay + ($this->jitter)($delay);
            if ($next <= $this->int($job['deadline'] ?? 0)) {
                $this->retryLater($jobUid, $this->int($job['config_uid'] ?? 0), $holder, $status, $reason, $attempts, $next, $now);

                return false;
            }
        }

        $state = $this->retryable($result) ? self::JOB_ABANDONED : self::JOB_FAILED;
        $this->finish($jobUid, $holder, $state, $status, $reason, $attempts, $now);
        // The older pending jobs of the config would send the same task as it
        // is now; they end with this one, so the attempts stay bounded per
        // config rather than per stored change (A2A-PUSH5).
        $this->supersedeOlder($job, $now);
        if ($attempts >= self::MAX_ATTEMPTS) {
            // The attempts of the config are used up: the changes written
            // while this attempt was on its way end with it, so the next
            // change starts a new count and is due at once.
            $connection = $this->connection();
            $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier(A2aPushConfigStore::OUTBOX_TABLE)
                . ' SET state = ?, last_reason = ?, tstamp = ? WHERE config_uid = ? AND state = ? AND uid <> ? AND attempts >= ?',
                [self::JOB_ABANDONED, 'MAX_ATTEMPTS', $now, $this->int($job['config_uid'] ?? 0), self::JOB_PENDING, $jobUid, self::MAX_ATTEMPTS],
                [Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT],
            );
        }

        $this->logger?->warning('An A2A push was not delivered', ['job' => $jobUid, 'state' => $state, 'reason' => $reason, 'attempts' => $attempts]);

        return false;
    }

    /**
     * Whether another attempt may succeed. A redirect counts as a failed
     * attempt and is retried like one: it is never followed (A2A-PUSH5).
     */
    private function retryable(A2aTransportResult $result): bool
    {
        if ($result->outcome !== A2aTransportOutcome::RESPONSE) {
            return $result->reason !== 'ADDRESS_REFUSED';
        }

        return in_array($result->status, self::RETRY_STATUSES, true) || ($result->status >= 300 && $result->status < 400);
    }

    /**
     * The request of a config, null when its task is gone, false when the
     * URL is not https outside the Testing context.
     *
     * @param array<string, mixed> $config
     */
    private function request(array $config): A2aHttpRequest|false|null
    {
        $task = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)
            ->select(['*'], A2aTaskStore::TABLE, ['uid' => $this->int($config['task_uid'] ?? 0), 'owner_client_uid' => $this->int($config['owner_client_uid'] ?? 0)])
            ->fetchAssociative();
        if (!is_array($task)) {
            return null;
        }

        $url = is_string($config['url'] ?? null) ? $config['url'] : '';
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' && !$this->guards->permitsPlainHttp()) {
            return false;
        }

        $headers = ['Content-Type' => 'application/a2a+json'];
        if (($config['auth_scheme'] ?? '') === 'Bearer') {
            $credential = $this->codec->decode(is_string($config['credentials'] ?? null) ? $config['credentials'] : '', AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL);
            if ($credential !== '') {
                // A2A-D8: the credential travels in Authorization only; the
                // config's token is never a header.
                $headers['Authorization'] = 'Bearer ' . $credential;
            }
        }

        return new A2aHttpRequest(
            'POST',
            $url,
            $headers,
            json_encode(['task' => $this->view->render($task, 0)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '',
            self::CONNECT_TIMEOUT_SECONDS,
            self::TIMEOUT_SECONDS,
            self::MAX_RESPONSE_BYTES,
            'A2A push notification',
            A2aUrlGuardFactory::GROUP_EXTERNAL,
        );
    }

    /**
     * @param array<string, mixed> $job
     */
    private function supersedeOlder(array $job, int $now): void
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(A2aPushConfigStore::OUTBOX_TABLE)
            . ' SET state = ?, tstamp = ? WHERE config_uid = ? AND state = ? AND revision < ? AND lease_until < ?',
            [self::JOB_SUPERSEDED, $now, $this->int($job['config_uid'] ?? 0), self::JOB_PENDING, $this->int($job['revision'] ?? 0), $now],
            [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT],
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stopConfig(array $config, string $state, string $reason, int $now): void
    {
        $connection = $this->connection();
        $configUid  = $this->int($config['uid'] ?? 0);
        $connection->update(A2aPushConfigStore::TABLE, ['state' => $state, 'reason' => $reason, 'tstamp' => $now], ['uid' => $configUid, 'state' => A2aPushConfigStore::STATE_ACTIVE]);
        $connection->update(A2aPushConfigStore::OUTBOX_TABLE, ['state' => self::JOB_DROPPED, 'last_reason' => 'CONFIG_' . strtoupper($state), 'tstamp' => $now], ['config_uid' => $configUid, 'state' => self::JOB_PENDING]);
    }

    /**
     * A delivery that succeeded ends the failing streak of its config: a job
     * written while it was on its way took over the attempts and the wait of
     * that streak; it starts with all attempts and is due at once.
     */
    private function freshStart(int $jobUid, int $configUid, int $now): void
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(A2aPushConfigStore::OUTBOX_TABLE)
            . ' SET attempts = 0, next_attempt = CASE WHEN next_attempt > ? THEN ? ELSE next_attempt END, tstamp = ?'
            . ' WHERE config_uid = ? AND state = ? AND uid <> ?',
            [$now, $now, $now, $configUid, self::JOB_PENDING, $jobUid],
            [Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
        );
    }

    private function retryLater(int $jobUid, int $configUid, string $holder, int $status, string $reason, int $attempts, int $next, int $now): void
    {
        $connection = $this->connection();
        $connection->update(
            A2aPushConfigStore::OUTBOX_TABLE,
            ['attempts' => $attempts, 'next_attempt' => $next, 'lease_holder' => '', 'lease_until' => 0, 'last_status' => $status, 'last_reason' => $reason, 'tstamp' => $now],
            ['uid' => $jobUid, 'lease_holder' => $holder],
        );
        // A job of the config written while this attempt was on its way took
        // over the state from before it; it waits for the same backoff.
        $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(A2aPushConfigStore::OUTBOX_TABLE)
            . ' SET attempts = ?, next_attempt = ?, tstamp = ? WHERE config_uid = ? AND state = ? AND uid <> ? AND attempts <= ?',
            [$attempts, $next, $now, $configUid, self::JOB_PENDING, $jobUid, $attempts],
            [Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT],
        );
    }

    private function finish(int $jobUid, string $holder, string $state, int $status, string $reason, int $attempts, int $now): void
    {
        $this->connection()->update(
            A2aPushConfigStore::OUTBOX_TABLE,
            ['state' => $state, 'attempts' => $attempts, 'lease_holder' => '', 'lease_until' => 0, 'last_status' => $status, 'last_reason' => $reason, 'tstamp' => $now],
            ['uid' => $jobUid, 'lease_holder' => $holder],
        );
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(A2aPushConfigStore::OUTBOX_TABLE);
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
