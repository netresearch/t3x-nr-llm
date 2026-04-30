<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Netresearch\NrLlm\Domain\Model\Task;

/**
 * Resolves a `Task`'s `input_type` configuration into the actual
 * input string that gets fed to the LLM.
 *
 * Concrete implementation: `TaskInputResolver`. The interface exists so
 * the slice 13c `TaskExecutionService` can be unit-tested without
 * standing up the full reader chain (and so future input sources can
 * be wired in via decorator without touching every call site).
 *
 * Behaviour mirrors the controller's pre-13b `getInputData()` private
 * helper: returns the input string for known input types, or the
 * empty string for unknown / static types.
 */
interface TaskInputResolverInterface
{
    /**
     * Resolve the configured input source for the given task.
     *
     * Returns a localised placeholder string when the source is
     * misconfigured or unreadable (matches the pre-13b behaviour);
     * never throws.
     */
    public function resolve(Task $task): string;
}
