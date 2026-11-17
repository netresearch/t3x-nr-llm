<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\SkillApprovalState;
use Netresearch\NrLlm\Domain\Enum\SkillInclusion;
use Netresearch\NrLlm\Domain\Enum\SkillRole;
use Netresearch\NrLlm\Domain\Enum\SkillUsageState;
use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SkillUsageEntry;
use Netresearch\NrLlm\Domain\ValueObject\SkillUse;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;

/**
 * Which skill versions one segment of a run has passed to a model call
 * (ADR-321).
 *
 * Created per segment by the loop, like the remote-call budget, because the
 * loop is a container singleton. The loop asks it before every send for the
 * versions that send carries and no earlier send of the segment carried, and
 * after a load for the version the load appended. Nothing is recorded for a
 * skill that was only configured or only offered in the load catalogue: a
 * version enters the ledger when it is composed into the prompt, loaded, or
 * held by a resumed segment, and is reported as passed only at a send.
 *
 * The version is fixed where it entered: the composition's digest, the load's
 * pin, the resumed state's pin; so is the role of a composed version. The
 * approval is read when the usage is recorded and is the one that held then,
 * and a loaded or carried version takes its role from it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class SkillUsageLedger
{
    /** @var array<string, SkillUse> composed versions by key */
    private array $composed = [];

    /** @var array<string, true> keys of versions a load appended */
    private array $loaded = [];

    /** @var array<string, true> keys of versions a send of this segment carried */
    private array $passed = [];

    /**
     * @param list<SkillUse>     $composed    what this segment's assembly put into the prompt
     * @param array<int, string> $identifiers the skill identifiers by uid, for versions the composition did not name
     */
    public function __construct(
        array $composed,
        private readonly array $identifiers = [],
        private readonly ?SkillPinCheck $approvals = null,
    ) {
        foreach ($composed as $use) {
            $this->composed[$use->key()] = $use;
        }
    }

    /**
     * The versions a load appended: the pins held after it that were not held
     * before it.
     *
     * @param list<SkillPin> $before
     * @param list<SkillPin> $after
     *
     * @return list<SkillUsageEntry>
     */
    public function loadedBetween(array $before, array $after): array
    {
        $held = [];
        foreach ($before as $pin) {
            $held[SkillUse::keyOf($pin->skillUid, $pin->versionDigest)] = true;
        }

        $entries = [];
        foreach ($after as $pin) {
            $key = SkillUse::keyOf($pin->skillUid, $pin->versionDigest);
            if (isset($held[$key])) {
                continue;
            }

            $this->loaded[$key] = true;
            $entries[]          = $this->pinEntry($pin, SkillUsageState::LOADED);
        }

        return $entries;
    }

    /**
     * The versions the next send carries for the first time in this segment:
     * every instruction pin the run holds and every composed context version.
     * Marks them passed, so the next call reports only what is new.
     *
     * @param list<SkillPin> $held the pins the transcript holds at this send
     *
     * @return list<SkillUsageEntry>
     */
    public function passing(array $held): array
    {
        $entries = [];
        foreach ($held as $pin) {
            $key = SkillUse::keyOf($pin->skillUid, $pin->versionDigest);
            if (isset($this->passed[$key])) {
                continue;
            }

            $this->passed[$key] = true;
            $entries[]          = $this->pinEntry($pin, SkillUsageState::PASSED);
        }

        foreach ($this->composed as $key => $use) {
            if ($use->role !== SkillRole::CONTEXT || isset($this->passed[$key])) {
                continue;
            }

            $this->passed[$key] = true;
            $entries[]          = new SkillUsageEntry(
                $use->skillUid,
                $use->sourceUid,
                $use->versionDigest,
                $use->identifier,
                SkillRole::CONTEXT,
                SkillInclusion::COMPOSED,
                SkillUsageState::PASSED,
                SkillApprovalState::NONE,
            );
        }

        return $entries;
    }

    private function pinEntry(SkillPin $pin, SkillUsageState $state): SkillUsageEntry
    {
        $key      = SkillUse::keyOf($pin->skillUid, $pin->versionDigest);
        $use      = $this->composed[$key] ?? null;
        $approval = $this->approvals?->heldApproval($pin);

        $inclusion = match (true) {
            $use instanceof SkillUse   => SkillInclusion::COMPOSED,
            isset($this->loaded[$key]) => SkillInclusion::LOADED,
            default                    => SkillInclusion::CARRIED,
        };

        $role = match (true) {
            $use instanceof SkillUse                                 => $use->role,
            $approval instanceof SkillApproval && $approval->process => SkillRole::PROCESS,
            default                                                  => SkillRole::INSTRUCTION,
        };
        // A composed process names no identifier when its record is attached
        // on demand only; the configuration's skills name it then.
        $identifier = $use instanceof SkillUse && $use->identifier !== '' ? $use->identifier : ($this->identifiers[$pin->skillUid] ?? '');

        return new SkillUsageEntry(
            $pin->skillUid,
            $pin->sourceUid,
            $pin->versionDigest,
            $identifier,
            $role,
            $inclusion,
            $state,
            $approval instanceof SkillApproval ? SkillApprovalState::APPROVED : SkillApprovalState::UNKNOWN,
            $approval instanceof SkillApproval ? $approval->uid : 0,
        );
    }
}
