<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use RuntimeException;
use TYPO3\CMS\Core\DataHandling\Event\IsTableExcludedFromReferenceIndexEvent;

/**
 * A listener of an installation that throws while the reference index is
 * updated — the step the DataHandler runs after the hooks of a run, and one
 * that runs code of the installation although it calls no hook: listeners,
 * FlexForm events, soft-reference parsers (ADR-206).
 *
 * Registered by the fixture extension `nrllm_failing_listener_fixture` and
 * switched on per test; the test resets the switch in tearDown.
 */
final class FailsInTheReferenceIndexListener
{
    public static bool $fail = false;

    public function __invoke(IsTableExcludedFromReferenceIndexEvent $event): void
    {
        if (self::$fail && $event->getTable() === 'tt_content') {
            throw new RuntimeException('A test listener of the reference index fails', 1790000007);
        }
    }
}
