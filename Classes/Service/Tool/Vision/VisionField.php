<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Vision;

/**
 * What the extension configuration says about one field a vision editor action
 * fills (ADR-235). `$maxLength` of 0, the shipped value, sets no limit of its
 * own: the tool's ceiling applies and the prompt asks for a length in words.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class VisionField
{
    public function __construct(
        public string $name,
        public bool $enabled,
        public int $maxLength,
        public string $prompt,
    ) {}

    /**
     * The length the model is asked for. A tenth below the limit, because a
     * model counts characters badly and an over-long value is refused on the
     * approval card.
     */
    public function targetLength(): int
    {
        return (int) floor($this->maxLength * 0.9);
    }
}
