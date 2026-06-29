<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use LogicException;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolRegistry::class)]
final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function collectsAndLooksUpByName(): void
    {
        $a = new FakeTool('alpha');
        $b = new FakeTool('beta');
        $r = new ToolRegistry([$a, $b]);

        self::assertSame($a, $r->get('alpha'));
        self::assertNull($r->get('missing'));
        self::assertSame(['alpha', 'beta'], $r->names());
    }

    #[Test]
    public function specsReturnsAllOrFilteredByAllowList(): void
    {
        $r = new ToolRegistry([new FakeTool('alpha'), new FakeTool('beta')]);

        self::assertSame(['alpha', 'beta'], array_map(static fn(ToolSpec $s): string => $s->name, $r->specs()));
        self::assertSame(['beta'], array_map(static fn(ToolSpec $s): string => $s->name, $r->specs(['beta'])));
        self::assertSame([], $r->specs(['unknown'])); // unknown declared names dropped
        self::assertSame([], $r->specs([]));          // explicit empty allow-list => no tools
    }

    #[Test]
    public function duplicateToolNameThrows(): void
    {
        $this->expectException(LogicException::class);
        self::assertInstanceOf(ToolRegistry::class, new ToolRegistry([new FakeTool('dup'), new FakeTool('dup')]));
    }
}
