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

    #[Test]
    public function theInputDigestIsNotTheApprovalDigestOfTheSameState(): void
    {
        // ADR-150: an input pause is bound over more than its pending calls, so
        // the approval digest cannot stand in for it — a client that echoed one
        // back for the other would be refused, which is the intended behaviour
        // and worth pinning.
        $digest = new PendingTurnDigest();
        $state  = $this->inputStateWith(['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]);

        self::assertNotSame($digest->forState($state), $digest->forInputState($state));
    }

    #[Test]
    public function aChangedInputSchemaChangesTheInputDigest(): void
    {
        // The schema is what the operator's form — and the pre-claim validation
        // — were built from. Two states with identical pending calls but
        // different schemas are different turns to submit against.
        $digest = new PendingTurnDigest();

        self::assertNotSame(
            $digest->forInputState($this->inputStateWith(['type' => 'object', 'properties' => ['city' => ['type' => 'string']]])),
            $digest->forInputState($this->inputStateWith(['type' => 'object', 'properties' => ['city' => ['type' => 'string'], 'iban' => ['type' => 'string']]])),
        );
    }

    #[Test]
    public function aChangedInputToolChangesTheInputDigest(): void
    {
        // The target tool is what resumeWithInput() dispatches the submitted
        // values onto; it is part of what the submission was written for.
        $schema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]];
        $digest = new PendingTurnDigest();

        self::assertNotSame(
            $digest->forInputState($this->inputStateWith($schema, 'ask_user')),
            $digest->forInputState($this->inputStateWith($schema, 'ask_admin')),
        );
    }

    /**
     * @param list<array<string, mixed>> $calls
     */
    private function stateWith(array $calls): SuspendedRunState
    {
        return new SuspendedRunState([['role' => 'user', 'content' => 'do it']], $calls, 1, 5, 2);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function inputStateWith(array $schema, string $tool = 'ask_user'): SuspendedRunState
    {
        $calls = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ask_user', 'arguments' => '{}']]];

        return new SuspendedRunState([['role' => 'user', 'content' => 'do it']], $calls, 1, 5, 2, null, [], $tool, $schema);
    }
}
