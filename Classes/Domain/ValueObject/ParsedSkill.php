<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;

final readonly class ParsedSkill
{
    /**
     * @param array<string,mixed> $rawFrontmatter
     */
    public function __construct(
        public string $path,
        public string $name,
        public string $description,
        public string $body,
        public array $rawFrontmatter,
        public SupportStatus $supportStatus,
        public string $unsupportedNotes = '',
    ) {}
}
