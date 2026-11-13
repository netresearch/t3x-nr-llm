<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * The pause a run waits in, as a decider sees it before deciding (ADR-281,
 * spec 051 A2A-MT2).
 *
 * `$generation` names this pause: the run's next event sequence while it
 * waits. Every segment that runs records events, so a run that went on and
 * paused again is at a later generation, also when the new pause asks the
 * same question. `$turnDigest` is the {@see PendingTurnDigest} of the pending
 * turn, the value an {@see InputSubmission} or an {@see ApprovalDecision}
 * carries.
 *
 * Only what a decider needs to answer: the input tool and its schema. The
 * suspended transcript, the pending calls and their arguments stay with the
 * run (reading them needs the read grant, which a decider may lack).
 *
 * @api
 */
final readonly class PendingPause
{
    public const KIND_INPUT = 'input';

    public const KIND_APPROVAL = 'approval';

    public function __construct(
        public string $runUuid,
        public string $kind,
        public int $generation,
        public string $turnDigest,
        /** On an input pause the tool whose typed input is asked for; null on an approval pause. */
        public ?string $inputToolName = null,
        /**
         * On an input pause the declared schema of that input; `[]` on an approval pause.
         *
         * @var array<string, mixed>
         */
        public array $inputSchema = [],
        /** The workspace the run was started in; null for a run that did not record it. */
        public ?int $workspaceId = null,
    ) {}

    public function isInput(): bool
    {
        return $this->kind === self::KIND_INPUT;
    }
}
