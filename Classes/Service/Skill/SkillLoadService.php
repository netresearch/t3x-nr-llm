<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\SkillCatalogue;
use Netresearch\NrLlm\Domain\ValueObject\SkillCatalogueEntry;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;

/**
 * The catalogue of loadable skills and the load decision (ADR-214 item 4).
 *
 * A load re-checks every condition of the catalogue at the moment of the
 * load, from stored values: the uid is in this run's catalogue, the skill is
 * still attached 'on_demand', the record is enabled, not hidden, deleted,
 * orphaned or a process skill, its stored digest is the one the catalogue
 * listed, its stored source is the one the run's copy and the catalogue name,
 * and that version still composes as an instruction (an unrevoked
 * approval for the digest and source, provenance at or above the threshold).
 *
 * Loading is idempotent per skill and digest: a skill whose section the run
 * already holds at that digest appends nothing; one held at another digest is
 * refused and keeps the version the run has.
 *
 * Admission against the context window (item 7) is the loop's step, because
 * it needs the transcript and the tool schemas of the next send.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillLoadService
{
    public function __construct(
        private SkillComposer $composer,
        private SkillLoadLookupInterface $lookup,
    ) {}

    public function catalogue(LlmConfiguration $configuration): SkillCatalogue
    {
        return $this->composer->catalogue(SkillInjectionService::toList($configuration->getSkillsOnDemand()));
    }

    /**
     * @param list<SkillPin> $held the pins of the sections the run's transcript already holds
     */
    public function load(int $skillUid, SkillCatalogue $catalogue, array $held, LlmConfiguration $configuration): SkillLoadOutcome
    {
        $entry = $catalogue->find($skillUid);
        if (!$entry instanceof SkillCatalogueEntry) {
            return SkillLoadOutcome::refused(sprintf("Skill %d is not in this run's skill catalogue.", $skillUid));
        }

        foreach ($held as $pin) {
            if ($pin->skillUid !== $skillUid) {
                continue;
            }

            return $pin->versionDigest === $entry->versionDigest
                ? SkillLoadOutcome::alreadyLoaded(sprintf('Skill "%s" is already loaded.', $entry->name))
                : SkillLoadOutcome::refused(sprintf('Skill "%s" is already loaded in another version; this run keeps that version.', $entry->name));
        }

        if (!$this->lookup->isAttachedOnDemand((int)$configuration->getUid(), $skillUid)) {
            return SkillLoadOutcome::refused(sprintf('Skill "%s" is no longer offered by this configuration.', $entry->name));
        }

        if ($this->lookup->loadableDigest($skillUid) !== $entry->versionDigest) {
            return SkillLoadOutcome::refused(sprintf('Skill "%s" changed or was disabled since this run started; it cannot be loaded.', $entry->name));
        }

        $skill  = $this->attachedOnDemand($configuration, $skillUid);
        $stored = $this->lookup->trustLevel($skillUid);
        if (!$skill instanceof Skill || $stored === null) {
            return SkillLoadOutcome::refused(sprintf('Skill "%s" is not an approved instruction right now; it cannot be loaded.', $entry->name));
        }

        $source = $this->lookup->storedSource($skillUid);
        if ($source === null || $source !== $skill->getSource() || $source !== $entry->source) {
            // An approval names a digest and a source. A record moved to
            // another source since the run read it would otherwise be
            // measured against the source the run's copy still names.
            return SkillLoadOutcome::refused(sprintf('Skill "%s" changed its source since this run started; it cannot be loaded.', $entry->name));
        }

        // The trust floor is measured against the level stored now, not the
        // one the run's object was read with.
        $skill = clone $skill;
        $skill->setTrustLevel($stored);

        $composed = $this->composer->composeInstruction($skill, $entry->versionDigest);
        if ($composed === null) {
            return SkillLoadOutcome::refused(sprintf('Skill "%s" is not an approved instruction right now; it cannot be loaded.', $entry->name));
        }

        return SkillLoadOutcome::loaded(
            sprintf('Skill "%s" is loaded. Its instructions are now part of your system instructions.', $entry->name),
            $composed['section'],
            $composed['pin'],
            $entry->name,
        );
    }

    private function attachedOnDemand(LlmConfiguration $configuration, int $skillUid): ?Skill
    {
        foreach ($configuration->getSkillsOnDemand() as $skill) {
            if ($skill->getUid() === $skillUid) {
                return $skill;
            }
        }

        return null;
    }
}
