<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps what it is told, as "level: message", so a test can assert that a call
 * reported nothing, or exactly one thing.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $records = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = (is_scalar($level) ? (string) $level : 'unknown') . ': ' . $message;
    }
}
