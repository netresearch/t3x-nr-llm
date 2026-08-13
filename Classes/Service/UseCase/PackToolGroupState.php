<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

/**
 * One tool group a pack recommends, as this installation currently answers for
 * it (ADR-168).
 *
 * The template used to print the raw string, so `contnet` looked exactly like
 * `content`. `registered` is the difference: no registered tool declares this
 * group, so nothing in the Tools module carries that name and the recommendation
 * points at a switch that does not exist.
 *
 * `labelKey` is null outside the curated {@see \Netresearch\NrLlm\Domain\Enum\ToolGroup}
 * taxonomy — a third-party group is registered and switchable, it simply has no
 * translated name. Null therefore says "no translation", never "unknown".
 */
final readonly class PackToolGroupState
{
    public function __construct(
        public string $group,
        public bool $registered,
        public bool $enabled,
        public ?string $labelKey = null,
    ) {}
}
