<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use Netresearch\NrLlm\Domain\Enum\ProcessInvocationRefusal;

/**
 * The runtime refused to start or continue a process (ADR-214 item 10,
 * ADR-228). Nothing ran: the run is settled failed before its first provider
 * call, with this class as its error class. The reason is typed for a caller
 * that reacts to it; the message names it for an editor.
 */
final class ProcessInvocationRefusedException extends AgentRuntimeException
{
    public function __construct(
        string $runUuid,
        public readonly ProcessInvocationRefusal $reason,
        string $message,
    ) {
        parent::__construct($runUuid, $message);
    }

    public static function forRun(string $runUuid, ProcessInvocationRefusal $reason, string $detail = ''): self
    {
        $message = match ($reason) {
            ProcessInvocationRefusal::CONFIGURATION_NOT_PERMITTED => 'You may not use the configuration this process runs on.',
            ProcessInvocationRefusal::SKILL_NOT_ATTACHED          => 'The skill is not attached to this configuration.',
            ProcessInvocationRefusal::SKILL_DISABLED              => 'The skill is disabled.',
            ProcessInvocationRefusal::NOT_A_PROCESS               => 'The skill is not a process skill.',
            ProcessInvocationRefusal::NOT_AN_INSTRUCTION          => 'The current version of the skill is not approved as an instruction.',
            ProcessInvocationRefusal::PROCESS_HELD                => 'This conversation already runs another process; end it first.',
            ProcessInvocationRefusal::SECOND_APPROVER_REQUIRED    => 'The configuration sets require_second_approver, under which nobody could apply the proposals of a process.',
            ProcessInvocationRefusal::SERVICE_ACCOUNT             => 'A service account cannot run a process: only the person who starts it may decide its proposals.',
            ProcessInvocationRefusal::SUBJECT_NOT_READABLE        => 'You may not read the record this process is about.',
            ProcessInvocationRefusal::PREDECESSOR_MISSING         => 'The previous turn of this conversation no longer exists; start a new conversation.',
            ProcessInvocationRefusal::PREDECESSOR_NOT_PERMITTED   => 'Only the person who started this conversation, or an administrator, may continue it.',
            ProcessInvocationRefusal::PREDECESSOR_NOT_FINISHED    => 'The previous turn of this process still waits or runs; finish or withdraw it first.',
            ProcessInvocationRefusal::LINEAGE_NOT_STORED          => 'The process could not be recorded for the next turn, so it was not started.',
        };

        return new self($runUuid, $reason, $detail !== '' ? $message . ' (' . $detail . ')' : $message);
    }
}
