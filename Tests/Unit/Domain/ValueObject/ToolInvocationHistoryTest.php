<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

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
        $state = new SuspendedRunState([], [], 1, 0, 0, invocationHistory: $history);
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
                'entries' => [['tool' => 'read', 'outcome' => 'invented', 'target' => null]],
                'complete' => true,
            ],
        ];
        yield 'unusable target' => [
            [
                'entries' => [
                    [
                        'tool' => 'read',
                        'outcome' => 'ok',
                        'target' => ['kind' => 'pages', 'identifier' => ''],
                    ],
                ],
                'complete' => true,
            ],
        ];
        yield 'non-list entries' => [
            [
                'entries' => [2 => ['tool' => 'read', 'outcome' => 'ok', 'target' => null]],
                'complete' => true,
            ],
        ];
    }
}
