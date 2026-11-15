<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskProjector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One open stream of a task, for `SendStreamingMessage` and
 * `SubscribeToTask` (spec 051 A2A-SSE1 to A2A-SSE5, A2A-D5, ADR-283).
 *
 * - **Snapshot and cursor (A2A-SSE2).** The caller reads the task row once;
 *   its `revision` is the cursor, and the snapshot is that row rendered as
 *   `GetTask` renders it. After the snapshot only journal events newer than
 *   the cursor are sent, in revision order. The journal is written from
 *   committed state only (A2A-R14), so a stream never announces what was not
 *   stored. `Last-Event-ID` is not read: recovery is a new stream or `GetTask`.
 * - **Events.** A journal event is the task as committed at one revision. It
 *   becomes an `artifactUpdate` per artifact the client has not been sent in
 *   that form (`append` false, `lastChunk` true: an artifact is always sent
 *   whole), then a `statusUpdate` with the event's state. The artifacts come
 *   before the status that announces them, as the TCK's scenarios order them.
 * - **Hold-back (A2A-SSE5).** The journal carries an artifact only once the
 *   run has completed and its answer has passed the output checks the run
 *   applies; no fragment is ever journalled. Every stream therefore sends
 *   status progress until the whole artifact is there, for every profile,
 *   whether or not it asks for a full-text check.
 * - **Close (A2A-D5, A2A-SSE4).** After the event that carries a terminal
 *   state or INPUT_REQUIRED. AUTH_REQUIRED keeps the stream open. A stream
 *   also ends after `streamMaxSeconds`, when a frame is larger than
 *   `maxFrameBytes`, when its slot is lost, and when its client is no longer
 *   valid (checked with every lease renewal, so a revoked client reads at
 *   most {@see self::RENEW_SECONDS} longer); switching streaming off ends
 *   open streams within the same interval. Ending a stream never touches
 *   the task.
 * - **Keep-alive.** A comment line before {@see self::KEEPALIVE_SECONDS}
 *   pass without a write.
 * - **The first event always names the task.** A snapshot larger than a frame
 *   is sent without history and artifacts (and, if need be, without its
 *   status message); the client reads the rest with `GetTask`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTaskStream
{
    public const KEEPALIVE_SECONDS = 15;

    public const RENEW_SECONDS = 10;

    private const POLL_MICROSECONDS = 250000;

    private LoggerInterface $logger;

    /**
     * @param (Closure(int): void)|null $sleep sleeps the given microseconds; a test steps time with it
     * @param (Closure(): float)|null   $clock seconds since the epoch
     */
    public function __construct(
        private A2aTaskView $view,
        private A2aTaskProjector $projector,
        private A2aStreamSlots $slots,
        private A2aAuthenticator $authenticator,
        private A2aServerSettings $settings,
        ?LoggerInterface $logger = null,
        private ?Closure $sleep = null,
        private ?Closure $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Stream a task the client owns, from its row as read by the caller.
     *
     * @param array<string, mixed> $row           the task row; its revision is the cursor
     * @param int|null             $historyLength of the snapshot, as GetTask reads it
     */
    public function stream(A2aInboundClient $client, array $row, string $holder, int|string|null $requestId, A2aSseWriter $writer, ?int $historyLength = null): void
    {
        $taskUid   = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
        $cursor    = is_numeric($row['revision'] ?? null) ? (int) $row['revision'] : 0;
        $taskId    = is_string($row['task_id'] ?? null) ? $row['task_id'] : '';
        $contextId = is_string($row['context_id'] ?? null) ? $row['context_id'] : '';

        $snapshot = $this->view->render($row, $historyLength);
        if (!$writer->event(['jsonrpc' => '2.0', 'id' => $requestId, 'result' => ['task' => $snapshot]])) {
            // The client must learn the task even when its snapshot is larger
            // than a frame: then the task goes without its history and
            // artifacts, and without its status message if need be, and the
            // client reads the rest with GetTask.
            $status   = is_array($snapshot['status'] ?? null) ? $snapshot['status'] : [];
            $snapshot = ['id' => $taskId, 'contextId' => $contextId, 'status' => $status];
            if (!$writer->event(['jsonrpc' => '2.0', 'id' => $requestId, 'result' => ['task' => $snapshot]])) {
                unset($status['message']);
                $snapshot['status'] = $status;
                if (!$this->send($writer, $requestId, ['task' => $snapshot], $taskId)) {
                    return;
                }
            }
        }

        $sent = [];
        foreach ($this->artifactsOf($snapshot['artifacts'] ?? null) as $artifact) {
            $sent[$this->artifactKey($artifact)] = $this->digest($artifact);
        }

        $state = is_array($snapshot['status'] ?? null) && is_string($snapshot['status']['state'] ?? null) ? A2aTaskState::tryFrom($snapshot['status']['state']) : null;
        if (self::closesAt($state)) {
            return;
        }

        $started  = $this->now();
        $deadline = $started + $this->settings->streamMaxSeconds();
        $renewed  = $started;
        while ($this->now() < $deadline) {
            foreach ($this->projector->eventsAfter($taskUid, $cursor) as $event) {
                $cursor  = $event['revision'];
                $payload = $event['payload'];
                foreach ($this->artifactsOf($payload['artifacts'] ?? null) as $artifact) {
                    $key    = $this->artifactKey($artifact);
                    $digest = $this->digest($artifact);
                    if (($sent[$key] ?? null) === $digest) {
                        continue;
                    }

                    $sent[$key] = $digest;
                    if (!$this->send($writer, $requestId, ['artifactUpdate' => ['taskId' => $taskId, 'contextId' => $contextId, 'artifact' => $artifact, 'append' => false, 'lastChunk' => true]], $taskId)) {
                        return;
                    }
                }

                $update = ['taskId' => $taskId, 'contextId' => $contextId, 'status' => $this->status($payload)];
                if (is_array($payload['metadata'] ?? null) && $payload['metadata'] !== []) {
                    $update['metadata'] = $payload['metadata'];
                }

                if (!$this->send($writer, $requestId, ['statusUpdate' => $update], $taskId)) {
                    return;
                }

                if (self::closesAt(A2aTaskState::tryFrom(is_string($payload['state'] ?? null) ? $payload['state'] : ''))) {
                    return;
                }
            }

            if ($this->now() - $renewed >= self::RENEW_SECONDS) {
                $renewed = $this->now();
                if (!$this->settings->streamingEnabled() || !$this->slots->renew($holder, (int) $renewed) || !$this->authenticator->stillValid($client, (int) $renewed)) {
                    return;
                }
            }

            // One poll interval early, so the gap never exceeds the interval.
            if ($writer->idleSeconds() >= self::KEEPALIVE_SECONDS - 1) {
                $writer->comment('keep-alive');
            }

            ($this->sleep ?? usleep(...))(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Whether a stream closes after an event carrying this state (A2A-D5): a
     * terminal state or INPUT_REQUIRED; AUTH_REQUIRED and the active states
     * keep it open.
     */
    public static function closesAt(?A2aTaskState $state): bool
    {
        return $state instanceof A2aTaskState && ($state->isTerminal() || $state === A2aTaskState::INPUT_REQUIRED);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function send(A2aSseWriter $writer, int|string|null $requestId, array $result, string $taskId): bool
    {
        if ($writer->event(['jsonrpc' => '2.0', 'id' => $requestId, 'result' => $result])) {
            return true;
        }

        $this->logger->warning('An A2A stream ended: an event is larger than a2a.server.maxFrameBytes; the client reads the task with GetTask', ['task' => $taskId]);

        return false;
    }

    /**
     * The `TaskStatus` of a journal event.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function status(array $payload): array
    {
        $status = ['state' => is_string($payload['state'] ?? null) ? $payload['state'] : A2aTaskState::UNSPECIFIED->value];
        if (is_array($payload['statusMessage'] ?? null)) {
            $status['message'] = $payload['statusMessage'];
        }

        $timestamp = is_numeric($payload['statusTimestamp'] ?? null) ? A2aTaskView::timestamp((int) $payload['statusTimestamp']) : null;
        if ($timestamp !== null) {
            $status['timestamp'] = $timestamp;
        }

        return $status;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artifactsOf(mixed $artifacts): array
    {
        if (!is_array($artifacts)) {
            return [];
        }

        $list = [];
        foreach ($artifacts as $artifact) {
            if (is_array($artifact)) {
                $object = [];
                foreach ($artifact as $key => $value) {
                    $object[(string) $key] = $value;
                }

                $list[] = $object;
            }
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $artifact
     */
    private function artifactKey(array $artifact): string
    {
        return is_string($artifact['artifactId'] ?? null) ? $artifact['artifactId'] : $this->digest($artifact);
    }

    /**
     * @param array<string, mixed> $artifact
     */
    private function digest(array $artifact): string
    {
        return hash('sha256', json_encode($artifact, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function now(): float
    {
        return $this->clock instanceof Closure ? ($this->clock)() : microtime(true);
    }
}
