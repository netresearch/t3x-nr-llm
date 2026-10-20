<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Process;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Service\Agent\Process\ProcessPinProbe;

/**
 * Says the named runs hold a process pin (ADR-214), keyed by run uuid, and
 * that a pin list holds one when it names one of the given skills. `everyRun`
 * answers yes to both questions, whatever the pins. `unknown` makes
 * processPinOf() answer that the lookup failed for a run that holds one.
 */
final readonly class ProcessPinProbeStub implements ProcessPinProbe
{
    /**
     * @param list<string>        $pinnedRunUuids
     * @param list<int>           $processSkillUids
     * @param list<SkillApproval> $snapshots
     */
    public function __construct(
        private array $pinnedRunUuids = [],
        private bool $everyRun = false,
        private array $processSkillUids = [],
        private bool $unknown = false,
        // The approval snapshots processSnapshot() answers with, matched by
        // skill, source and digest.
        private array $snapshots = [],
    ) {}

    public function holdsProcessPin(AgentRun $run): bool
    {
        return $this->everyRun || in_array($run->uuid, $this->pinnedRunUuids, true);
    }

    public function processPinOf(AgentRun $run): ?bool
    {
        return $this->unknown && $this->holdsProcessPin($run) ? null : $this->holdsProcessPin($run);
    }

    public function processSnapshot(SkillPin $pin): ?SkillApproval
    {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->skillUid === $pin->skillUid && $snapshot->sourceUid === $pin->sourceUid && $snapshot->versionDigest === $pin->versionDigest) {
                return $snapshot;
            }
        }

        return null;
    }

    public function anyProcessPin(array $pins): bool
    {
        foreach ($pins as $pin) {
            if (in_array($pin->skillUid, $this->processSkillUids, true)) {
                return true;
            }
        }

        return $this->everyRun;
    }
}
