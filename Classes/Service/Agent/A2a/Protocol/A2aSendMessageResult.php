<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * A `SendMessageResponse`: a task OR a message, never both, never neither
 * (A2A-C3). A caller has to handle both; {@see self::isTask()} decides which.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendMessageResult
{
    private function __construct(
        public ?A2aTask $task,
        public ?A2aMessage $message,
    ) {}

    public static function ofTask(A2aTask $task): self
    {
        return new self($task, null);
    }

    public static function ofMessage(A2aMessage $message): self
    {
        return new self(null, $message);
    }

    /**
     * @phpstan-assert-if-true !null $this->task
     * @phpstan-assert-if-true null $this->message
     *
     * @phpstan-assert-if-false null $this->task
     * @phpstan-assert-if-false !null $this->message
     */
    public function isTask(): bool
    {
        return $this->task instanceof A2aTask;
    }
}
