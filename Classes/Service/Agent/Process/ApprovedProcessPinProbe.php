<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Process;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers {@see ProcessPinProbe} from the pins a run holds and the approval
 * snapshots of their digests (ADR-214 items 6 and 9).
 *
 * A waiting run's pins are read from its stored suspended state, where the
 * tool loop keeps them for the resume's pin check, on the row the caller
 * already loaded. A pin is a process pin when
 * an approval of its skill, source and digest says the version is a process —
 * revoked or not, because a revocation stops the run at its next resume and
 * must not loosen the rules until then.
 *
 * Fail-closed where it matters: a pin whose approval cannot be found, or a
 * lookup that fails, counts as a process pin, so the stricter rules apply. A
 * failed lookup is reported as such by {@see processPinOf()}, so the one
 * consequence that cannot be undone can wait for a lookup that works. A
 * run whose stored state cannot be read answers false instead (see the
 * interface): the resume path refuses it as unreadable before anything runs.
 *
 * @internal
 */
final readonly class ApprovedProcessPinProbe implements ProcessPinProbe
{
    public function __construct(
        private SkillApprovalRepositoryInterface $approvals,
        private ?LoggerInterface $logger = null,
    ) {}

    public function holdsProcessPin(AgentRun $run): bool
    {
        return $this->processPinOf($run) ?? true;
    }

    public function processPinOf(AgentRun $run): ?bool
    {
        if ($run->suspendedState === null || $run->suspendedState === '') {
            return false;
        }

        $decoded = json_decode($run->suspendedState, true);
        if (!is_array($decoded)) {
            return false;
        }

        /** @var array<string, mixed> $decoded */
        return $this->processPinAmong(SuspendedRunState::fromArray($decoded)->skillPins);
    }

    public function anyProcessPin(array $pins): bool
    {
        return $this->processPinAmong($pins) ?? true;
    }

    /**
     * True as soon as one pin is a process pin or has no approval; null when
     * no pin is and a lookup failed; false when every approval was read and
     * none is a process.
     *
     * @param list<SkillPin> $pins
     */
    private function processPinAmong(array $pins): ?bool
    {
        $failed = false;
        foreach ($pins as $pin) {
            try {
                $known = false;
                foreach ($this->approvals->findBySkill($pin->skillUid) as $approval) {
                    if ($approval->sourceUid !== $pin->sourceUid || $approval->versionDigest !== $pin->versionDigest) {
                        continue;
                    }

                    if ($approval->process) {
                        return true;
                    }

                    $known = true;
                }
            } catch (Throwable $exception) {
                $this->logger?->warning('The approval of a pinned skill version could not be read; the process rules apply.', ['exception' => $exception]);
                $failed = true;
                continue;
            }

            if (!$known) {
                return true;
            }
        }

        return $failed ? null : false;
    }
}
