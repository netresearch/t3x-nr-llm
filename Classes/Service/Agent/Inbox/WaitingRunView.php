<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Inbox;

/**
 * A run awaiting a human decision, resolved from its persisted SuspendedRunState
 * into a logic-free shape the approvals-inbox template renders (ADR-109).
 *
 * `$mode` drives the card:
 * - `approval` — render {@see $pendingCalls} and an Approve/Deny form carrying
 *   {@see $turnDigest} (the reviewed turn, for the stale-review guard).
 * - `input` — render a schema-driven form from {@see $inputFields}.
 * - `unreadable` — fail-closed: a corrupt/undecodable state or a schema that
 *   cannot be rendered as a form; show {@see $unreadableReason}, no action.
 */
final readonly class WaitingRunView
{
    public const MODE_APPROVAL = 'approval';

    public const MODE_INPUT = 'input';

    public const MODE_UNREADABLE = 'unreadable';

    /**
     * @param self::MODE_*          $mode
     * @param list<PendingCallView> $pendingCalls approval mode only
     * @param list<InputFieldView>  $inputFields  input mode only
     */
    public function __construct(
        public string $runUuid,
        public string $mode,
        public int $createdAt,
        public string $configLabel,
        public ?string $turnDigest = null,
        public array $pendingCalls = [],
        public array $inputFields = [],
        public ?string $unreadableReason = null,
    ) {}
}
