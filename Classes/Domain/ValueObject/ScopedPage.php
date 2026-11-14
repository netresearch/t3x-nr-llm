<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * One page an assignment names, and how far below it the assignment reaches
 * (ADR-295): depth 0 is the page and the records on it, depth 1 adds its
 * direct subpages with their records, and so on.
 *
 * @api
 */
final readonly class ScopedPage
{
    public function __construct(
        public int $uid,
        public int $depth = 0,
    ) {
        if ($uid < 1) {
            throw new InvalidArgumentException(sprintf('A scoped page needs a positive uid, got %d.', $uid), 1799990230);
        }

        if ($depth < 0 || $depth > AssignmentScope::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf('A scoped page needs a depth between 0 and %d, got %d.', AssignmentScope::MAX_DEPTH, $depth), 1799990231);
        }
    }

    /**
     * @return array{uid: int, depth: int}
     */
    public function toArray(): array
    {
        return ['uid' => $this->uid, 'depth' => $this->depth];
    }
}
