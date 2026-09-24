<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Web;

use Netresearch\NrLlm\Service\Tool\Web\BoundedSinkStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundedSinkStream::class)]
final class BoundedSinkStreamTest extends TestCase
{
    #[Test]
    public function writesBelowTheLimitAreAcceptedInFull(): void
    {
        $sink = new BoundedSinkStream(10);

        self::assertSame(4, $sink->write('abcd'));
        self::assertSame(6, $sink->write('efghij'));
        self::assertFalse($sink->isTruncated());
        self::assertSame('abcdefghij', $sink->contents());
    }

    #[Test]
    public function theWriteThatCrossesTheLimitIsShortSoCurlAborts(): void
    {
        $sink = new BoundedSinkStream(10);
        $sink->write('abcdefgh');

        // curl compares this count with the chunk length; less means abort.
        self::assertSame(2, $sink->write('ijklmnop'));
        self::assertTrue($sink->isTruncated());
        self::assertSame(0, $sink->write('more'));
        self::assertSame('abcdefghij', $sink->contents());
    }
}
