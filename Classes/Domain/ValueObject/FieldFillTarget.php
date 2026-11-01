<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The field a background run fills (ADR-237). The run's origin is where it is
 * kept, so the history names it and a queued run of the same field can be found.
 */
final readonly class FieldFillTarget
{
    public const ORIGIN_PREFIX = 'fill:';

    public function __construct(
        public string $table,
        public int $uid,
        public string $field,
    ) {}

    public function origin(): string
    {
        return sprintf('%s%s:%d:%s', self::ORIGIN_PREFIX, $this->table, $this->uid, $this->field);
    }

    public static function fromOrigin(string $origin): ?self
    {
        if (preg_match('/^fill:([a-zA-Z0-9_]+):([1-9][0-9]*):([a-zA-Z0-9_]+)$/', $origin, $match) !== 1) {
            return null;
        }

        return new self($match[1], (int) $match[2], $match[3]);
    }
}
