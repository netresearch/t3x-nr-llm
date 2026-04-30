<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

/**
 * Reads the tail of TYPO3's deprecation log for the Task
 * "deprecation log" input source.
 *
 * Concrete implementation: `DeprecationLogReader`. The interface exists
 * so the slice 13b `TaskInputResolver` can be unit-tested without
 * touching the filesystem.
 */
interface DeprecationLogReaderInterface
{
    /**
     * Return the last `$maxLines` of the deprecation log as a single
     * string, or a localised placeholder when the log is missing or
     * unreadable.
     */
    public function readTail(int $maxLines = 100): string;
}
