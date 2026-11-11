<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Protocol;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps every log record written to it.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }
}
