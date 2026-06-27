<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

final readonly class MarketplaceEntry
{
    public function __construct(
        public string $owner,
        public string $repo,
        public ?string $ref = null,
    ) {}
}
