<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use InvalidArgumentException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;

/**
 * One record's place in a batch of editor actions (ADR-162).
 *
 * Lives beside the planner rather than in `Domain\ValueObject` because it
 * carries an {@see AgentRunRequest}: core may not reach into the tool and agent
 * packages (ADR-090), and a batch entry is a tool-module type.
 *
 * Either the catalogue offered the action for this record — then `$request` is
 * the ordinary run that would perform it, exactly the object the single-record
 * path builds — or it did not, and `$skipReasonKey` says so in a translatable
 * sentence. Never both, never neither: a record that is silently absent from the
 * batch is the failure this type exists to prevent.
 */
final readonly class EditorActionBatchEntry
{
    public function __construct(
        public int $recordUid,
        public ?AgentRunRequest $request = null,
        /** `LLL:` key of the reason this record is not part of the batch. */
        public ?string $skipReasonKey = null,
    ) {
        if (($request instanceof AgentRunRequest) === ($skipReasonKey !== null)) {
            throw new InvalidArgumentException(
                'A batch entry carries either a run request or a skip reason, never both and never neither.',
                1786700001,
            );
        }
    }

    public function isRunnable(): bool
    {
        return $this->request instanceof AgentRunRequest;
    }
}
