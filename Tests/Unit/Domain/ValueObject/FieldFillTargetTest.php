<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\FieldFillTarget;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(FieldFillTarget::class)]
final class FieldFillTargetTest extends AbstractUnitTestCase
{
    #[Test]
    public function anOriginNamesItsField(): void
    {
        $target = FieldFillTarget::fromOrigin((new FieldFillTarget('tx_news_domain_model_news', 42, 'teaser'))->origin());

        self::assertInstanceOf(FieldFillTarget::class, $target);
        self::assertSame('tx_news_domain_model_news', $target->table);
        self::assertSame(42, $target->uid);
        self::assertSame('teaser', $target->field);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherOrigins(): iterable
    {
        yield 'no origin' => [''];
        yield 'a task' => ['task:digest'];
        yield 'a batch' => ['batch:set_file_alternative_text:sys_file:7'];
        yield 'no uid' => ['fill:pages:0:description'];
        yield 'trailing' => ['fill:pages:1:description:x'];
        yield 'prefix only' => ['xfill:pages:1:description'];
    }

    #[Test]
    #[DataProvider('otherOrigins')]
    public function anyOtherOriginNamesNoField(string $origin): void
    {
        self::assertNull(FieldFillTarget::fromOrigin($origin));
    }
}
