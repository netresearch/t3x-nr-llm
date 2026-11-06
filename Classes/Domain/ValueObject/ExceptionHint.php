<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ExceptionHintRefusal;

/**
 * A model's hint for one logged exception: its likely cause and next step
 * (ADR-258).
 *
 * `$guess` is decided by the extension, not reported by the model: a hint is
 * a guess unless the model pointed at a frame of the entry's own stack that
 * lies in the project's code. `$frame` is that cited frame's index into
 * {@see LogExceptionEntry::$frames}, or null when the model cited none or one
 * the stack does not have.
 *
 * A refused hint carries `$reason` and its `$refusalArguments`, which the
 * backend module words in the administrator's language, and `$refusal`, the
 * same reason in English for the console command.
 */
final readonly class ExceptionHint
{
    /**
     * @param list<string> $refusalArguments
     */
    private function __construct(
        public string $cause,
        public string $nextStep,
        public ?int $frame,
        public bool $guess,
        public bool $messageWithheld,
        public ?string $refusal,
        public ?ExceptionHintRefusal $reason = null,
        public array $refusalArguments = [],
    ) {}

    public static function answered(string $cause, string $nextStep, ?int $frame, bool $guess, bool $messageWithheld): self
    {
        return new self($cause, $nextStep, $frame, $guess, $messageWithheld, null);
    }

    public static function refused(ExceptionHintRefusal $reason, string ...$arguments): self
    {
        $arguments = array_values($arguments);

        return new self('', '', null, true, true, $reason->english(...$arguments), $reason, $arguments);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
