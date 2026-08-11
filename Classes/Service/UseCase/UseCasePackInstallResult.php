<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

/**
 * What an install actually changed (ADR-163).
 *
 * Created and skipped are reported separately because on a re-run they are the
 * whole story: "3 skipped, 0 created" is the evidence that a second install
 * left the operator's records alone.
 */
final readonly class UseCasePackInstallResult
{
    /**
     * @param list<string> $createdTasks
     * @param list<string> $skippedTasks
     * @param list<string> $createdSnippets
     * @param list<string> $skippedSnippets
     */
    public function __construct(
        public bool $createdConfiguration,
        public array $createdTasks,
        public array $skippedTasks,
        public array $createdSnippets,
        public array $skippedSnippets,
    ) {}

    public function getCreatedCount(): int
    {
        return ($this->createdConfiguration ? 1 : 0)
            + count($this->createdTasks)
            + count($this->createdSnippets);
    }

    public function getSkippedCount(): int
    {
        return ($this->createdConfiguration ? 0 : 1)
            + count($this->skippedTasks)
            + count($this->skippedSnippets);
    }
}
