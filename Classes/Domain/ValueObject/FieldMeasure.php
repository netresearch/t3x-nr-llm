<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The length of a proposed value and, where the installation configured one,
 * the range it is recommended to fall into (ADR-214, item 9).
 *
 * `count` is the number of characters of the PLAIN text, counted with
 * `mb_strlen()`: for a rich-text value the markup is stripped and the entities
 * decoded first, so the count is what a reader of the page sees. `min` and
 * `max` come from the extension configuration, never from the model; both are
 * null when no range is configured for the field.
 *
 * @api
 */
final readonly class FieldMeasure
{
    public function __construct(
        public int $count,
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    /**
     * @return array{count: int, min: int|null, max: int|null}
     */
    public function toArray(): array
    {
        return ['count' => $this->count, 'min' => $this->min, 'max' => $this->max];
    }
}
