<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Process;

use Netresearch\NrLlm\Domain\ValueObject\DroppedSource;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;

/**
 * What a run starts or continues with (ADR-228), after the start checks: the
 * process pin the loop composes from its snapshot, the record the process is
 * about, and the pins the continuation dropped, which the run reports.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class PreparedProcess
{
    /**
     * @param list<DroppedSource> $dropped
     */
    public function __construct(
        public ?SkillPin $pin = null,
        public ?SubjectRecord $subject = null,
        public array $dropped = [],
        // The process starts with this run, rather than continuing from the
        // predecessor, so its section is admitted against the window.
        public bool $newStart = false,
    ) {}
}
