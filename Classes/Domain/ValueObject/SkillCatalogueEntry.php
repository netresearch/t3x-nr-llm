<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One loadable skill of a {@see SkillCatalogue}: named by its uid (identifiers
 * are unique only per source), with the version digest the catalogue saw.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillCatalogueEntry
{
    public function __construct(
        public int $skillUid,
        public string $name,
        public string $description,
        public string $versionDigest,
    ) {}
}
