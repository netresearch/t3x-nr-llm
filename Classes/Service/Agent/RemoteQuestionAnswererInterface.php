<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;

/**
 * Sends the answer of a run's initiator to the external operation that asked
 * for it (spec 060, ADR-311): the remote task a waiting delegation is bound
 * to. The resume coordinator decides who may answer and when; this decides
 * nothing about that and only carries the answer to the task the checkpoint
 * names.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface RemoteQuestionAnswererInterface
{
    /**
     * @param string $messageId the id the answer is sent under; the same id
     *                          for the same pause, so a repeated execution sends nothing
     *
     * @return ToolResult|DependencyCheckpoint the pending call's result when
     *                                         the operation settled or the answer could not go out; the checkpoint to
     *                                         wait on again when it goes on
     */
    public function answer(DependencyCheckpoint $checkpoint, string $answer, string $messageId, int $runUid): ToolResult|DependencyCheckpoint;
}
