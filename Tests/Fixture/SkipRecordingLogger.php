<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that answers one question: which configuration identifiers
 * were logged under a given wording?
 *
 * Used where the log line IS the asserted behaviour — the fallback candidate
 * loop reports a skipped chain entry only that way.
 */
final class SkipRecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, configuration: mixed}> */
    private array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'message'       => (string)$message,
            'configuration' => $context['configuration'] ?? null,
        ];
    }

    /**
     * The `configuration` context value of every record whose message contains
     * $messageNeedle, in order.
     *
     * @return list<mixed>
     */
    public function skipped(string $messageNeedle): array
    {
        $skipped = [];
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $messageNeedle)) {
                $skipped[] = $record['configuration'];
            }
        }

        return $skipped;
    }
}
