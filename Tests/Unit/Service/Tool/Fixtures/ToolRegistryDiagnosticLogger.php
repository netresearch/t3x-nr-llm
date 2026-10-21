<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * Records the attempted diagnostic before optionally refusing its delivery.
 */
final class ToolRegistryDiagnosticLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function __construct(private readonly ?Throwable $failure = null) {}

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(
        $level,
        string|Stringable $message,
        array $context = [],
    ): void {
        $this->records[] = [
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
