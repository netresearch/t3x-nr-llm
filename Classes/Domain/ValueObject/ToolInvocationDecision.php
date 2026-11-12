<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A decision with stable codes, suitable for recording without arguments.
 *
 * A refusal may also carry an explanation: one sentence for the person behind
 * the run, in their language, that the refused call's result passes on. It is
 * never recorded; the codes are what the governance log keeps.
 *
 * @api
 */
final readonly class ToolInvocationDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason = 'allowed',
        public string $ruleIdentifier = '',
        public string $explanation = '',
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

    public static function deny(string $reason, string $explanation = ''): self
    {
        return new self(false, $reason, '', $explanation);
    }

    public function forRule(string $identifier): self
    {
        return new self($this->allowed, $this->reason, $identifier, $this->explanation);
    }
}
