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
 * A frame also keeps what the effect check of the call has to judge before the
 * rows are gone (ADR-295, #1442): a discard hard-deletes the draft it removes,
 * and a publish replaces the live row's position, so the hook judges them while
 * the rows can still be read and notes what lies outside the scope here; the
 * effect check reports it with the rest and the call is rolled back.
 *
 * @internal
 */
final class AssignmentScopeGuard
{
    /** @var list<array{scope: AssignmentScope, checker: AssignmentScopeChecker, workspace: int, refusals: list<ScopeRefusal>, observed: list<array{refusal: ScopeRefusal, workspace: int}>}> */
    private static array $frames = [];

    /**
     * @param int $workspace the workspace the call writes in
     */
    public static function arm(AssignmentScope $scope, ?AssignmentScopeChecker $checker = null, int $workspace = 0): void
    {
        self::$frames[] = ['scope' => $scope, 'checker' => $checker ?? new AssignmentScopeChecker(), 'workspace' => max(0, $workspace), 'refusals' => [], 'observed' => []];
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

    /**
     * The workspace the call that is executing writes in.
     */
    public static function workspace(): int
    {
        $last = array_key_last(self::$frames);

        return $last === null ? 0 : self::$frames[$last]['workspace'];
    }

    /**
     * Note a record the call changed outside the scope, judged while it could
     * still be read: the effect check reports it and the call is rolled back.
     */
    public static function observe(ScopeRefusal $refusal, int $workspace): void
    {
        $last = array_key_last(self::$frames);
        if ($last !== null) {
            self::$frames[$last]['observed'][] = ['refusal' => $refusal, 'workspace' => $workspace];
        }
    }

    /**
     * What the innermost frame noted with {@see self::observe()}, without
     * disarming it.
     *
     * @return list<array{refusal: ScopeRefusal, workspace: int}>
     */
    public static function observed(): array
    {
        $last = array_key_last(self::$frames);

        return $last === null ? [] : self::$frames[$last]['observed'];
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
            self::$frames[$outer]['observed'] = [...self::$frames[$outer]['observed'], ...$frame['observed']];
        }

        return $frame['refusals'];
    }

    public static function reset(): void
    {
        self::$frames = [];
    }
}
