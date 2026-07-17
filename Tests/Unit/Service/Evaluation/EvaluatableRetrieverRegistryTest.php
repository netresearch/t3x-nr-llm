<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use LogicException;
use Netresearch\NrLlm\Service\Evaluation\EvaluatableRetrieverRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticRetriever;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EvaluatableRetrieverRegistry::class)]
final class EvaluatableRetrieverRegistryTest extends TestCase
{
    #[Test]
    public function collectsRetrieversByIdentifier(): void
    {
        $registry = new EvaluatableRetrieverRegistry([
            new StaticRetriever(identifier: 'a.one'),
            new StaticRetriever(identifier: 'b.two'),
        ]);

        self::assertCount(2, $registry->all());
        self::assertSame(['a.one', 'b.two'], $registry->identifiers());
        self::assertNotNull($registry->findByIdentifier('a.one'));
        self::assertNull($registry->findByIdentifier('missing'));
    }

    #[Test]
    public function duplicateIdentifierFailsFast(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1794000060);
        self::assertInstanceOf(EvaluatableRetrieverRegistry::class, new EvaluatableRetrieverRegistry([
            new StaticRetriever(identifier: 'a.one'),
            new StaticRetriever(identifier: 'a.one'),
        ]));
    }
}
