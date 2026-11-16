<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A shared service that keeps state which is only valid for one run.
 *
 * A queue worker keeps its services for its whole life, and TYPO3 registers
 * no service resetter for it. A memo that one run built (for example the
 * storages {@see \Netresearch\NrLlm\Service\Tool\FalStorageGate} built for a
 * user's mounts) would otherwise answer for every later run, also after an
 * administrator changed what that user may reach. The runtime calls
 * {@see self::reset()} before a queued run starts and again when it ends.
 */
#[AutoconfigureTag(name: self::TAG)]
interface RunScopedStateInterface
{
    public const TAG = 'nr_llm.run_scoped_state';

    /**
     * Forget everything a previous run left behind.
     */
    public function reset(): void;
}
