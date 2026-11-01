<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\FieldFill;

use Netresearch\NrLlm\Domain\Enum\FieldFillOutcome;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\FieldFillTarget;

/**
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface FieldFillWriterInterface
{
    /**
     * Whether the field is still opted in. Asked before the model is, so that
     * withdrawing the opt-in stops what is queued.
     */
    public function accepts(FieldFillTarget $target): bool;

    public function write(FieldFillTarget $target, string $answer, AiActorContext $actor, string $runUuid = ''): FieldFillOutcome;
}
