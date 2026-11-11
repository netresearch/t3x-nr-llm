<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tool name of a delegation is unique per (agent record, skill id): pairs
 * whose readable parts are the same still get different names, and every name
 * is one a provider accepts.
 */
#[CoversClass(A2aDelegationTool::class)]
final class A2aDelegationToolNameTest extends TestCase
{
    /**
     * @return iterable<string, array{array{int, string, string}, array{int, string, string}}>
     */
    public static function pairsThatReadAlike(): iterable
    {
        yield 'underscore inside the identifier' => [[1, 'ab', 'c_d'], [2, 'ab_c', 'd']];
        yield 'case of the skill id' => [[1, 'ab', 'Summarise'], [1, 'ab', 'summarise']];
        yield 'characters folded to an underscore' => [[1, 'ab', 'c.d'], [1, 'ab', 'c-d']];
        yield 'the same identifier on two records' => [[1, 'ab', 'c'], [2, 'ab', 'c']];
        yield 'long ids cut to the same prefix' => [[1, 'ab', str_repeat('x', 80) . '1'], [1, 'ab', str_repeat('x', 80) . '2']];
    }

    /**
     * @param array{int, string, string} $one
     * @param array{int, string, string} $other
     */
    #[Test]
    #[DataProvider('pairsThatReadAlike')]
    public function pairsThatReadAlikeGetDifferentNames(array $one, array $other): void
    {
        self::assertNotSame(A2aDelegationTool::nameFor(...$one), A2aDelegationTool::nameFor(...$other));
    }

    #[Test]
    public function aNameIsStableAndAcceptedByEveryProvider(): void
    {
        $name = A2aDelegationTool::nameFor(7, 'remote', 'Summarise ä/ö ' . str_repeat('y', 100));

        self::assertSame($name, A2aDelegationTool::nameFor(7, 'remote', 'Summarise ä/ö ' . str_repeat('y', 100)));
        self::assertMatchesRegularExpression('/^a2a_[a-z0-9_]+$/', $name);
        self::assertLessThanOrEqual(64, strlen($name));
        self::assertStringStartsWith('a2a_remote_summarise', $name);
    }
}
