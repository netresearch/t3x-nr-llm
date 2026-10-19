<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * An authoritative target reference supplied by a tool-specific resolver.
 *
 * @api
 */
final readonly class ToolInvocationTarget
{
    public function __construct(public string $kind, public string $identifier)
    {
        if (trim($kind) === '' || trim($identifier) === '') {
            throw new InvalidArgumentException(
                'A resolved invocation target needs a kind and identifier.',
                1791530401,
            );
        }
    }
}
