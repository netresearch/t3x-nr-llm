<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One parsed error-level entry from a TYPO3 file log (ADR-044).
 *
 * Produced by {@see \Netresearch\NrLlm\Service\Tool\LogExceptionReader} and
 * consumed by the error-analysis tools (`get_last_exception`, and
 * `probe_url`'s 5xx↔log correlation).
 *
 * @phpstan-type LogFrame array{file: string, line: int, call: string}
 */
final readonly class LogExceptionEntry
{
    /**
     * @param list<array{file: string, line: int, call: string}> $frames
     */
    public function __construct(
        public int $timestamp,
        public string $level,
        public string $component,
        public string $message,
        public ?string $exceptionClass = null,
        public array $frames = [],
    ) {}
}
