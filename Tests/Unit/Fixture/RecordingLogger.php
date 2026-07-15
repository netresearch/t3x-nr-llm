<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR-3 logger for unit tests.
 *
 * Captures every log() call (level, rendered message, context) so assertions
 * can verify a collaborator emitted the expected log line with the expected
 * context — a NullLogger swallows all of that.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }

    /**
     * The first captured record whose level matches and whose message contains
     * $messageNeedle, or null when none matched.
     *
     * @return array{level: mixed, message: string, context: array<array-key, mixed>}|null
     */
    public function firstMatching(string $level, string $messageNeedle): ?array
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $messageNeedle)) {
                return $record;
            }
        }

        return null;
    }
}
