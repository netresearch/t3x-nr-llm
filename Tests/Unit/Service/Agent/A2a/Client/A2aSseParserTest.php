<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Client;

use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aSseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The WHATWG event-stream rules the A2A client parses by (spec 051 A2A-SSE6).
 */
#[CoversClass(A2aSseParser::class)]
final class A2aSseParserTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, list<array{event: string, data: string}>}>
     */
    public static function streams(): iterable
    {
        yield 'LF lines' => [["data: a\n\n"], [['event' => 'message', 'data' => 'a']]];
        yield 'CRLF lines' => [["data: a\r\n\r\n"], [['event' => 'message', 'data' => 'a']]];
        yield 'CR lines' => [["data: a\r\r"], [['event' => 'message', 'data' => 'a']]];
        yield 'CRLF split between reads' => [["data: a\r", "\n\r", "\n"], [['event' => 'message', 'data' => 'a']]];
        yield 'a CRLF split between reads is one line end, not two' => [["data: a\r", "\ndata: b\n\n"], [['event' => 'message', 'data' => "a\nb"]]];
        yield 'two data lines join with LF' => [["data: a\ndata: b\n\n"], [['event' => 'message', 'data' => "a\nb"]]];
        yield 'one space after the colon is dropped, not two' => [["data:  a\n\n"], [['event' => 'message', 'data' => ' a']]];
        yield 'no space after the colon' => [["data:a\n\n"], [['event' => 'message', 'data' => 'a']]];
        yield 'comments are ignored' => [[": keep-alive\n\n: pad      \ndata: a\n\n"], [['event' => 'message', 'data' => 'a']]];
        yield 'an event without data is not dispatched' => [["event: x\n\n"], []];
        yield 'the event name is kept' => [["event: update\ndata: a\n\n"], [['event' => 'update', 'data' => 'a']]];
        yield 'id and retry are read and not used' => [["id: 7\nretry: 10\ndata: a\n\n"], [['event' => 'message', 'data' => 'a']]];
        yield 'a byte order mark at the start is dropped' => [["\xEF\xBB", "\xBFdata: a\n\n"], [['event' => 'message', 'data' => 'a']]];
        yield 'a UTF-8 sequence split between reads' => [["data: \xC3", "\xBC\n\n"], [['event' => 'message', 'data' => 'ü']]];
        yield 'a frame split in the middle' => [['da', 'ta: {"a":', '1}', "\n", "\n"], [['event' => 'message', 'data' => '{"a":1}']]];
        yield 'an unfinished event is not dispatched' => [["data: a\n"], []];
    }

    /**
     * @param list<string>                             $pieces
     * @param list<array{event: string, data: string}> $expected
     */
    #[Test]
    #[DataProvider('streams')]
    public function itParsesByTheEventStreamRules(array $pieces, array $expected): void
    {
        $parser = new A2aSseParser(1024);
        $events = [];
        foreach ($pieces as $piece) {
            array_push($events, ...$parser->feed($piece));
        }

        self::assertSame($expected, $events);
    }

    #[Test]
    public function aPartialEventAtTheEndIsReported(): void
    {
        $parser = new A2aSseParser(1024);
        $parser->feed("data: a\n\n");
        self::assertFalse($parser->hasPartialEvent());

        $parser->feed('data: b');
        self::assertTrue($parser->hasPartialEvent());
    }

    #[Test]
    public function anEventAboveTheLimitMakesTheStreamInvalid(): void
    {
        $parser = new A2aSseParser(8);

        self::assertSame([], $parser->feed("data: 12345\ndata: 6789\n"));
        self::assertNotNull($parser->failure());
    }

    #[Test]
    public function aLineAboveTheLimitIsRefusedBeforeItEnds(): void
    {
        $parser = new A2aSseParser(8);

        $parser->feed('data: 1234567890');
        self::assertNotNull($parser->failure());
    }

    /**
     * A complete line is held to the limit as well, not only a partial one.
     */
    #[Test]
    public function aCompleteLineAboveTheLimitIsRefused(): void
    {
        $parser = new A2aSseParser(16);

        self::assertSame([], $parser->feed('event: ' . str_repeat('x', 40) . "\ndata: a\n\n"));
        self::assertNotNull($parser->failure());
    }

    /**
     * Events completed before an invalid line in the same piece are returned.
     */
    #[Test]
    public function eventsBeforeAnInvalidLineAreStillReturned(): void
    {
        $parser = new A2aSseParser(16);

        self::assertSame([['event' => 'message', 'data' => 'a']], $parser->feed("data: a\n\n" . str_repeat('x', 40)));
        self::assertNotNull($parser->failure());
        self::assertSame([], $parser->feed("data: b\n\n"), 'nothing after the failure');
    }
}
