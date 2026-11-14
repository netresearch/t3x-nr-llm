<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;

/**
 * The scope the call that is executing is held to, for the DataHandler hook
 * (ADR-295).
 *
 * The tool loop arms it around `ToolInterface::execute()` and takes it
 * afterwards on every way out, as it does the change collector (ADR-292). The
 * hook is a global DataHandler hook with no handle on the loop, so the state
 * is static; it is inert while nothing is armed. Frames nest: a call made
 * inside a call has its own scope, and what it refused is reported to the
 * outer call too.
 *
 * @internal
 */
final class AssignmentScopeGuard
{
    /** @var list<array{scope: AssignmentScope, checker: AssignmentScopeChecker, refusals: list<ScopeRefusal>}> */
    private static array $frames = [];

    public static function arm(AssignmentScope $scope, ?AssignmentScopeChecker $checker = null): void
    {
        self::$frames[] = ['scope' => $scope, 'checker' => $checker ?? new AssignmentScopeChecker(), 'refusals' => []];
    }

    /**
     * How many calls have armed the guard and not taken it yet.
     */
    public static function depth(): int
    {
        return count(self::$frames);
    }

    public static function isArmed(): bool
    {
        return self::$frames !== [];
    }

    public static function scope(): ?AssignmentScope
    {
        $last = array_key_last(self::$frames);

        return $last === null ? null : self::$frames[$last]['scope'];
    }

    public static function checker(): ?AssignmentScopeChecker
    {
        $last = array_key_last(self::$frames);

        return $last === null ? null : self::$frames[$last]['checker'];
    }

    public static function refuse(ScopeRefusal $refusal): void
    {
        $last = array_key_last(self::$frames);
        if ($last !== null) {
            self::$frames[$last]['refusals'][] = $refusal;
        }
    }

    /**
     * Disarm the innermost frame and return what it refused.
     *
     * @return list<ScopeRefusal>
     */
    public static function take(): array
    {
        $frame = array_pop(self::$frames);
        if ($frame === null) {
            return [];
        }

        $outer = array_key_last(self::$frames);
        if ($outer !== null) {
            self::$frames[$outer]['refusals'] = [...self::$frames[$outer]['refusals'], ...$frame['refusals']];
        }

        return $frame['refusals'];
    }

    public static function reset(): void
    {
        self::$frames = [];
    }
}
