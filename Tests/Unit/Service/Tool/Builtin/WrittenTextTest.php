<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\Builtin\WrittenText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WrittenText::class)]
final class WrittenTextTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, WriteKind}>
     */
    public static function changes(): iterable
    {
        yield 'a single-line text' => [['title'], WriteKind::UPDATED];
        yield 'a text area' => [['bodytext'], WriteKind::UPDATED];
        yield 'a setting' => [['layout'], WriteKind::TEXT_UNCHANGED];
        yield 'a setting beside a text' => [['layout', 'title'], WriteKind::UPDATED];
        yield 'an unknown column' => [['nope'], WriteKind::TEXT_UNCHANGED];
        yield 'nothing' => [[], WriteKind::TEXT_UNCHANGED];
    }

    /**
     * @param list<string> $changed
     */
    #[Test]
    #[DataProvider('changes')]
    public function textMakesAnUpdateWrittenText(array $changed, WriteKind $expected): void
    {
        $tca            = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA'] = ['t' => ['columns' => [
            'title'    => ['config' => ['type' => 'input']],
            'bodytext' => ['config' => ['type' => 'text']],
            'layout'   => ['config' => ['type' => 'select']],
        ]]];

        try {
            self::assertSame($expected, WrittenText::kindOf('t', $changed));
        } finally {
            $GLOBALS['TCA'] = $tca;
        }
    }
}
