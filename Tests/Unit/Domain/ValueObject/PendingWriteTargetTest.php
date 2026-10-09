<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingWriteTarget::class)]
final class PendingWriteTargetTest extends TestCase
{
    /**
     * Two cards for the same record and fields carry equal targets, whatever
     * order the model wrote the fields in — the open-point key depends on it.
     */
    #[Test]
    public function theFieldsAreSortedAndFreeOfDuplicates(): void
    {
        $a = new PendingWriteTarget(new RecordReference('pages', 42), ['title', 'description', 'title']);
        $b = new PendingWriteTarget(new RecordReference('pages', 42), ['description', 'title']);

        self::assertSame(['description', 'title'], $a->fields);
        self::assertEquals($a, $b);
        self::assertSame(['table' => 'pages', 'uid' => 42, 'fields' => ['description', 'title']], $a->toArray());
        self::assertSame([], (new PendingWriteTarget(new RecordReference('pages', 42)))->fields);
    }

    #[Test]
    public function aFieldNameThatIsNoIdentifierIsRefused(): void
    {
        foreach (['', 'title; DROP', "title\n", str_repeat('a', 65), 'tïtle'] as $field) {
            try {
                $accepted = new PendingWriteTarget(new RecordReference('pages', 42), [$field]);
                self::fail('Accepted the field names ' . implode(', ', $accepted->fields));
            } catch (InvalidArgumentException $e) {
                self::assertSame(1791600101, $e->getCode());
            }
        }
    }

    /**
     * A caller of the public constructor that passes a non-string gets the
     * documented exception, not the TypeError preg_match() raises under strict
     * types.
     */
    #[Test]
    public function aFieldNameThatIsNoStringIsRefusedWithTheDocumentedException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791600101);

        // A non-string, as a caller ignoring the documented type could pass.
        $accepted = new PendingWriteTarget(new RecordReference('pages', 42), [42]); // @phpstan-ignore argument.type
        self::fail('Accepted the field names ' . implode(', ', $accepted->fields));
    }

    /**
     * Model-supplied arguments name a reachable record or nothing: every shape
     * that could not reach one is null, never an exception, because the call
     * itself is refused only when it runs.
     */
    #[Test]
    public function argumentsThatNameNoReachableRecordGiveNoTarget(): void
    {
        foreach ([
            'table not a string' => [42, 1, []],
            'table not an id'    => ['pages x', 1, []],
            'uid zero'           => ['pages', 0, []],
            'uid negative'       => ['pages', -3, []],
            'uid float'          => ['pages', 1.5, []],
            'uid text'           => ['pages', 'one', []],
            'uid leading zero'   => ['pages', '01', []],
            'uid missing'        => ['pages', null, []],
            'field not a string' => ['pages', 1, [7]],
            'field null'         => ['pages', 1, [null]],
            'field not an id'    => ['pages', 1, ['a-b']],
        ] as $case => [$table, $uid, $fields]) {
            self::assertNull(PendingWriteTarget::fromArguments($table, $uid, $fields), $case);
        }
    }

    #[Test]
    public function argumentsThatNameARecordGiveItsTarget(): void
    {
        $target = PendingWriteTarget::fromArguments('tt_content', '17', ['header', 'bodytext']);

        self::assertInstanceOf(PendingWriteTarget::class, $target);
        self::assertSame(['table' => 'tt_content', 'uid' => 17, 'fields' => ['bodytext', 'header']], $target->toArray());
        self::assertSame(['table' => 'pages', 'uid' => 3, 'fields' => []], PendingWriteTarget::fromArguments('pages', 3)?->toArray());
    }
}
