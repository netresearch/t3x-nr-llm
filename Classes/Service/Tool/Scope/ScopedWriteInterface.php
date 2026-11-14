<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

/**
 * Implemented by a write tool that can state, from its arguments alone, which
 * records and pages a call will touch (ADR-295).
 *
 * `RequireAssignmentScopeRule` checks that statement against the scope of the
 * run before the call. A write tool that does not implement it cannot be
 * bound to a scope and is refused while one applies. The statement is a
 * promise, and the second layer, the DataHandler hook, checks the writes the
 * call really makes against the same scope.
 *
 * @api
 */
interface ScopedWriteInterface
{
    /**
     * @param array<string, mixed> $arguments the arguments of the call, as the model sent them
     *
     * @return ScopedWrite|null null when the arguments name no target the tool could act on
     */
    public function scopedWrite(array $arguments): ?ScopedWrite;
}
