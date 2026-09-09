<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\ImageTokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageTokenUsage::class)]
final class ImageTokenUsageTest extends TestCase
{
    #[Test]
    public function carriesTheThreeCountsAResponseReports(): void
    {
        $tokens = new ImageTokenUsage(120, 800, 90);

        self::assertSame(120, $tokens->inputTokens);
        self::assertSame(800, $tokens->outputTokens);
        self::assertSame(90, $tokens->imageInputTokens);
    }

    #[Test]
    public function defaultsToNoAccountingAtAll(): void
    {
        $tokens = new ImageTokenUsage();

        self::assertSame(0, $tokens->inputTokens);
        self::assertSame(0, $tokens->outputTokens);
        self::assertSame(0, $tokens->imageInputTokens);
        self::assertTrue($tokens->isEmpty());
    }

    /**
     * `isEmpty()` asks whether there is anything to price by token, and image
     * input tokens alone are not that.
     *
     * They are a breakdown of `input_tokens`, not a count beside it: a
     * response that reports image tokens always reports an input total too.
     * Treating them as accounting on their own would price a dall-e call --
     * which sends no `usage` object at all -- by token instead of by image.
     *
     * @param array{int, int, int} $counts
     */
    #[Test]
    #[DataProvider('usageCounts')]
    public function emptinessFollowsTheTotalsRatherThanTheBreakdown(array $counts, bool $expected): void
    {
        [$input, $output, $imageInput] = $counts;

        self::assertSame($expected, (new ImageTokenUsage($input, $output, $imageInput))->isEmpty());
    }

    /**
     * @return iterable<string, array{array{int, int, int}, bool}>
     */
    public static function usageCounts(): iterable
    {
        yield 'nothing reported'      => [[0, 0, 0], true];
        yield 'image tokens only'     => [[0, 0, 90], true];
        yield 'input only'            => [[120, 0, 0], false];
        yield 'output only'           => [[0, 800, 0], false];
        yield 'a full gpt-image call' => [[120, 800, 90], false];
    }
}
