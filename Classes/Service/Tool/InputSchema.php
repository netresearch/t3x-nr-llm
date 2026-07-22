<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * The single authority on whether a tool's declared input schema (ADR-105) is
 * usable — one predicate shared by the tool loop's capture-time gate and the
 * AgentRuntime's rehydrate gate, so the two can never drift.
 *
 * A degenerate schema (empty, or with no declared shape) is a programming error,
 * NOT "accept anything": validating a submission against `[]` would return true
 * and let unvalidated user input flow into a tool. Both gates reject a
 * non-usable schema fail-closed (capture -> LogicException; rehydrate ->
 * CorruptSuspendedStateException).
 */
final class InputSchema
{
    /**
     * A schema is usable only if it declares a real shape: a `type`, or at least
     * one `properties` entry.
     *
     * @param array<string, mixed> $schema
     */
    public static function isUsable(array $schema): bool
    {
        if ($schema === []) {
            return false;
        }

        if (isset($schema['type'])) {
            return true;
        }

        return isset($schema['properties'])
            && is_array($schema['properties'])
            && $schema['properties'] !== [];
    }
}
