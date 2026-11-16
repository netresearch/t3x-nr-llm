<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A record state a tool observed (ADR-298): the keyed digest the invocation
 * history keeps, and the field values it was computed from, which the tool
 * judges so that its verdict and its digest describe the same snapshot.
 *
 * A tool takes one from
 * {@see \Netresearch\NrLlm\Service\Tool\InvocationDigests::observe()} and
 * reports it through {@see ToolResult::withObservedState()}.
 *
 * @api
 */
final readonly class ObservedState
{
    /** The format of a digest of version 1: the prefix and 64 hex digits. */
    public const DIGEST_PATTERN = '/^v1:[0-9a-f]{64}\z/';

    /**
     * @param array<string, string|null> $values the observed field values, by field name
     *
     * @internal construct through InvocationDigests::observe(); the format check below is what stays public
     */
    public function __construct(
        public string $digest,
        public array $values,
    ) {
        if (preg_match(self::DIGEST_PATTERN, $digest) !== 1) {
            throw new InvalidArgumentException('An observed state needs a digest of the form v1: and 64 hex digits.', 1794060001);
        }
    }
}
