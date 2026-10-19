<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The skills a run's model may load (ADR-214 item 4): the configuration's
 * 'on_demand' attachments whose current version is an approved instruction
 * and which are not process skills, as uid, name and description.
 *
 * Built once per loop segment. It is a list of candidates, not a grant: the
 * load re-checks every condition at the moment of the load.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillCatalogue
{
    /**
     * @param list<SkillCatalogueEntry> $entries
     */
    public function __construct(
        public array $entries = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function find(int $skillUid): ?SkillCatalogueEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->skillUid === $skillUid) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The catalogue as the model reads it, one line per skill. Every name and
     * description is covered by the digest an administrator approved.
     */
    public function describe(): string
    {
        $lines = [];
        foreach ($this->entries as $entry) {
            $lines[] = sprintf('- %d: %s — %s', $entry->skillUid, $entry->name, $entry->description);
        }

        return implode("\n", $lines);
    }
}
