<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The offers of one tool group, ready to render as one section (ADR-158).
 *
 * Grouping happens in the catalogue service rather than in Fluid because the
 * group's human name is a `LLL:` key that only the curated
 * {@see \Netresearch\NrLlm\Domain\Enum\ToolGroup} taxonomy has — `f:groupedFor`
 * would hand the template a raw identifier and no way back to the key.
 *
 * `$labelKey` is null for a group outside that taxonomy (a third-party tool's
 * group); the template then renders `$name`, which is what such a group has
 * instead of a name.
 */
final readonly class EditorActionOfferGroup
{
    /**
     * @param list<EditorActionOffer> $offers
     */
    public function __construct(
        public string $name,
        public ?string $labelKey,
        public array $offers,
    ) {}
}
