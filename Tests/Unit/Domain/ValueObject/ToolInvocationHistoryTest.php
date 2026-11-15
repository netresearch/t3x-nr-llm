<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationHistory;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolInvocationHistory::class)]
final class ToolInvocationHistoryTest extends TestCase
{
    #[Test]
    public function suspensionRoundTripPreservesHistoryOutsideTheTranscript(): void
    {
        $history = (new ToolInvocationHistory())->append(
            'fetch_record',
            'ok',
            new ToolInvocationTarget('pages', '7'),
        );
        $state    = new SuspendedRunState([], [], 1, 0, 0, invocationHistory: $history);
        $restored = SuspendedRunState::fromArray($state->toArray());
        self::assertSame(
            $history->toStored(),
            $restored->invocationHistory?->toStored(),
        );
        self::assertSame(
            $history->toStored(),
            $restored->withSkillPins([])->invocationHistory?->toStored(),
        );
        self::assertSame([], $restored->messages);
    }

    /**
     * An entry carries the digests it was given (ADR-298) through a
     * suspension, and omits a key it was given none for.
     */
    #[Test]
    public function digestsSurviveASuspensionAndAMissingOneIsOmitted(): void
    {
        $arguments = 'v1:' . str_repeat('0123456789abcdef', 4);
        $state     = 'v1:' . str_repeat('f', 64);
        $history   = (new ToolInvocationHistory())
            ->append('check_page_metadata', 'ok', null, $arguments, $state)
            ->append('update_page_metadata', 'denied', new ToolInvocationTarget('pages', '7'), $arguments)
            ->append('get_page_content', 'ok', null);

        $restored = SuspendedRunState::fromArray((new SuspendedRunState([], [], 1, 0, 0, invocationHistory: $history))->toArray())->invocationHistory;

        self::assertNotNull($restored);
        self::assertTrue($restored->complete);
        self::assertSame($history->toStored(), $restored->toStored());
        self::assertSame(['tool' => 'check_page_metadata', 'outcome' => 'ok', 'target' => null, 'arguments' => $arguments, 'state' => $state], $restored->entries[0]);
        self::assertArrayNotHasKey('state', $restored->entries[1]);
        self::assertSame(['tool' => 'get_page_content', 'outcome' => 'ok', 'target' => null], $restored->entries[2]);
    }

    /**
     * An entry stored before the digests existed has neither key and still
     * loads, so an old suspended state keeps resuming.
     */
    #[Test]
    public function anEntryStoredWithoutDigestsStillLoads(): void
    {
        $history = ToolInvocationHistory::fromStored([
            'entries'  => [['tool' => 'get_page_content', 'outcome' => 'ok', 'target' => null]],
            'complete' => true,
        ]);

        self::assertTrue($history->complete);
        self::assertSame([['tool' => 'get_page_content', 'outcome' => 'ok', 'target' => null]], $history->entries);
    }

    #[Test]
    public function appendRefusesADigestOfAnotherForm(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ToolInvocationHistory())->append('check_page_metadata', 'ok', null, 'sha256:abc');
    }

    #[Test]
    #[DataProvider('invalidStoredHistory')]
    public function unprovenHistoryNeverBecomesProvenEmpty(mixed $raw): void
    {
        $history = ToolInvocationHistory::fromStored($raw);
        self::assertFalse($history->complete);
        self::assertSame([], $history->entries);
        self::assertFalse($history->append('lookup', 'failed', null)->complete);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStoredHistory(): iterable
    {
        yield 'old state' => [null];
        yield 'missing flag' => [['entries' => []]];
        yield 'string flag' => [['entries' => [], 'complete' => 'true']];
        yield 'invalid outcome' => [
            [
                'entries'  => [['tool' => 'read', 'outcome' => 'invented', 'target' => null]],
                'complete' => true,
            ],
        ];
        yield 'unusable target' => [
            [
                'entries' => [
                    [
                        'tool'    => 'read',
                        'outcome' => 'ok',
                        'target'  => ['kind' => 'pages', 'identifier' => ''],
                    ],
                ],
                'complete' => true,
            ],
        ];
        yield 'malformed arguments digest' => [
            [
                'entries'  => [['tool' => 'check', 'outcome' => 'ok', 'target' => null, 'arguments' => 'v1:' . str_repeat('A', 64)]],
                'complete' => true,
            ],
        ];
        yield 'unknown digest version' => [
            [
                'entries'  => [['tool' => 'check', 'outcome' => 'ok', 'target' => null, 'state' => 'v2:' . str_repeat('a', 64)]],
                'complete' => true,
            ],
        ];
        yield 'digest stored as null' => [
            [
                'entries'  => [['tool' => 'check', 'outcome' => 'ok', 'target' => null, 'state' => null]],
                'complete' => true,
            ],
        ];
        yield 'digest with a trailing newline' => [
            [
                'entries'  => [['tool' => 'check', 'outcome' => 'ok', 'target' => null, 'arguments' => 'v1:' . str_repeat('a', 64) . "\n"]],
                'complete' => true,
            ],
        ];
        yield 'non-list entries' => [
            [
                'entries'  => [2 => ['tool' => 'read', 'outcome' => 'ok', 'target' => null]],
                'complete' => true,
            ],
        ];
    }
}
