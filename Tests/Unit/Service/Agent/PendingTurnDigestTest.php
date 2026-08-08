<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes {@see PendingTurnDigest} against the computation it was
 * extracted from (WaitingRunViewFactory::pendingTurnDigest, ADR-109).
 *
 * The digests already rendered into open backend tabs were produced by the old
 * inline code; a byte difference here would silently invalidate every one of
 * them, and the fail-closed guard would read as "everything is stale". The
 * expected values below are therefore recomputed from the ORIGINAL expression,
 * not copied from the new implementation.
 */
#[CoversClass(PendingTurnDigest::class)]
final class PendingTurnDigestTest extends TestCase
{
    #[Test]
    public function theDigestIsByteIdenticalToTheExtractedComputation(): void
    {
        $calls = [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{"uid":42}']],
            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'send_mail', 'arguments' => '{}']],
        ];

        // The original expression, verbatim.
        $json     = json_encode($calls, JSON_INVALID_UTF8_SUBSTITUTE);
        $expected = hash('sha256', $json !== false ? $json : serialize($calls));

        self::assertSame($expected, (new PendingTurnDigest())->forState($this->stateWith($calls)));
    }

    #[Test]
    public function theDigestCoversOnlyThePendingCallsAndNotTheRestOfTheState(): void
    {
        // The transcript and the counters change on every round; binding them in
        // would make every digest stale the moment the run moved at all.
        $calls = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{}']]];

        $digest = new PendingTurnDigest();

        self::assertSame(
            $digest->forState(new SuspendedRunState([['role' => 'user', 'content' => 'a']], $calls, 1, 5, 2)),
            $digest->forState(new SuspendedRunState([['role' => 'user', 'content' => 'b']], $calls, 9, 99, 77, ['x'], ['temperature' => 1])),
        );
    }

    #[Test]
    public function differentPendingCallsProduceDifferentDigests(): void
    {
        $digest = new PendingTurnDigest();

        $one = $digest->forState($this->stateWith([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{"uid":42}']]]));
        $two = $digest->forState($this->stateWith([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{"uid":43}']]]));

        self::assertNotSame($one, $two);
    }

    #[Test]
    public function anUnencodableTurnStillYieldsAComparableDigest(): void
    {
        // json_encode() fails on INF/NAN even with JSON_INVALID_UTF8_SUBSTITUTE.
        // The serialize() fallback keeps the digest a comparable value instead of
        // hashing the empty string — which would make every unencodable turn
        // collide with every other one.
        $calls = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => ['n' => INF]]]];

        $expected = hash('sha256', serialize($calls));

        self::assertSame($expected, (new PendingTurnDigest())->forState($this->stateWith($calls)));
    }

    /**
     * @param list<array<string, mixed>> $calls
     */
    private function stateWith(array $calls): SuspendedRunState
    {
        return new SuspendedRunState([['role' => 'user', 'content' => 'do it']], $calls, 1, 5, 2);
    }
}
