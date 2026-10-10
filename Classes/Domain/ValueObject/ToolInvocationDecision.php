<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A decision with stable codes, suitable for recording without arguments.
 *
 * @api
 */
final readonly class ToolInvocationDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason = 'allowed',
        public string $ruleIdentifier = '',
    ) {
        foreach ([$reason, $ruleIdentifier] as $code) {
            if ($reason === '' || $code !== '' && preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $code) !== 1) {
                throw new InvalidArgumentException(
                    'Invocation reasons and rule identifiers must be stable codes.',
                    1791530402,
                );
            }
        }
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }

    public function forRule(string $identifier): self
    {
        return new self($this->allowed, $this->reason, $identifier);
    }
}
