<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;

/**
 * In-memory {@see ToolAvailabilityServiceInterface} double for the
 * ToolLoopService unit tests.
 *
 * Carries a fixed list of globally-enabled tool names so a test can exercise
 * the fail-closed gate (intersection with the per-run allow-list) without a
 * database. The richer {@see states()} payload is unused by the loop and
 * returns an empty list.
 */
final readonly class FakeToolAvailability implements ToolAvailabilityServiceInterface
{
    /**
     * @param list<string> $enabledNames
     */
    public function __construct(private array $enabledNames) {}

    public function enabledNames(): array
    {
        return $this->enabledNames;
    }

    public function states(): array
    {
        return [];
    }

    public function groupStates(): array
    {
        return [];
    }
}
