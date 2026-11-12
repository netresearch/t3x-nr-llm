<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * What a run suspended on an external dependency stores beside its transcript
 * (ADR-281, spec 051 A2A-R3): the tool call the dependency answers, the
 * external operation, the actor and workspace, the deadline and the budget
 * state.
 *
 * It travels inside the sealed `suspended_state` column under the key
 * {@see self::STORAGE_KEY}, next to the {@see \Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState}
 * fields, so it is sealed with them and dropped with them when the run
 * settles. The `@api` state object itself is not widened.
 *
 * The external operation of the first kind of dependency is an A2A send
 * intent: `journalUid` is the row in `tx_nrllm_a2a_send`, `agentUid` the
 * remote agent record and `remoteTaskId` the task the remote created.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class DependencyCheckpoint
{
    public const STORAGE_KEY = 'dependency';

    public const KIND_A2A_TASK = 'a2a_task';

    /**
     * @param string   $toolCallId       the call the resume answers, and only that one (A2A-R4)
     * @param int      $ownerUid         the backend user the run acts as, the journal intent's owner
     * @param int|null $workspaceId      the workspace the run recorded (ADR-260); null when not known
     * @param int      $deadline         unix time after which the run resumes with a timeout result
     * @param int      $delegations      how many delegations this run had made, this one included
     * @param int      $transferredBytes request and response bytes of this run's delegations so far
     */
    public function __construct(
        public string $kind,
        public string $toolCallId,
        public string $toolName,
        public int $journalUid,
        public int $agentUid,
        public string $remoteTaskId,
        public int $ownerUid,
        public ?int $workspaceId,
        public int $suspendedAt,
        public int $deadline,
        public int $delegations,
        public int $transferredBytes,
    ) {}

    /**
     * The same checkpoint for the call the loop is executing.
     */
    public function forCall(string $toolCallId, string $toolName): self
    {
        return new self($this->kind, $toolCallId, $toolName, $this->journalUid, $this->agentUid, $this->remoteTaskId, $this->ownerUid, $this->workspaceId, $this->suspendedAt, $this->deadline, $this->delegations, $this->transferredBytes);
    }

    /**
     * @return array{kind: string, toolCallId: string, toolName: string, journalUid: int, agentUid: int, remoteTaskId: string, ownerUid: int, workspaceId: int|null, suspendedAt: int, deadline: int, delegations: int, transferredBytes: int}
     */
    public function toArray(): array
    {
        return [
            'kind'             => $this->kind,
            'toolCallId'       => $this->toolCallId,
            'toolName'         => $this->toolName,
            'journalUid'       => $this->journalUid,
            'agentUid'         => $this->agentUid,
            'remoteTaskId'     => $this->remoteTaskId,
            'ownerUid'         => $this->ownerUid,
            'workspaceId'      => $this->workspaceId,
            'suspendedAt'      => $this->suspendedAt,
            'deadline'         => $this->deadline,
            'delegations'      => $this->delegations,
            'transferredBytes' => $this->transferredBytes,
        ];
    }

    /**
     * The checkpoint a stored state carries, or null when it carries none or
     * one that is not complete: a resume never guesses the call it answers.
     *
     * @param array<array-key, mixed> $stored the decoded `suspended_state`
     */
    public static function fromStoredState(array $stored): ?self
    {
        $data = $stored[self::STORAGE_KEY] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $strings = [];
        foreach (['kind', 'toolCallId', 'toolName', 'remoteTaskId'] as $key) {
            $value = $data[$key] ?? null;
            if (!is_string($value) || $value === '') {
                return null;
            }

            $strings[$key] = $value;
        }

        $ints = [];
        foreach (['journalUid', 'agentUid', 'ownerUid', 'suspendedAt', 'deadline', 'delegations', 'transferredBytes'] as $key) {
            $value = $data[$key] ?? null;
            if (!is_int($value) || $value < 0) {
                return null;
            }

            $ints[$key] = $value;
        }

        if ($ints['journalUid'] < 1 || $ints['agentUid'] < 1 || $ints['ownerUid'] < 1) {
            return null;
        }

        $workspace = $data['workspaceId'] ?? null;

        return new self(
            $strings['kind'],
            $strings['toolCallId'],
            $strings['toolName'],
            $ints['journalUid'],
            $ints['agentUid'],
            $strings['remoteTaskId'],
            $ints['ownerUid'],
            is_int($workspace) && $workspace >= 0 ? $workspace : null,
            $ints['suspendedAt'],
            $ints['deadline'],
            $ints['delegations'],
            $ints['transferredBytes'],
        );
    }
}
