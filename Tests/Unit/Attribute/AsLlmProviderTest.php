<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Attribute;

use Attribute;
use Netresearch\NrLlm\Attribute\AsLlmProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

#[CoversClass(AsLlmProvider::class)]
class AsLlmProviderTest extends AbstractUnitTestCase
{
    #[Test]
    public function tagNameIsTheCompilerPassTagName(): void
    {
        self::assertSame('nr_llm.provider', AsLlmProvider::TAG_NAME);
    }

    #[Test]
    public function constructorDefaultsToZeroPriority(): void
    {
        $attribute = new AsLlmProvider();

        self::assertSame(0, $attribute->priority);
    }

    #[Test]
    public function constructorPersistsPriority(): void
    {
        $attribute = new AsLlmProvider(priority: 100);

        self::assertSame(100, $attribute->priority);
    }

    #[Test]
    public function isDeclaredAsClassAttribute(): void
    {
        $reflection = new ReflectionClass(AsLlmProvider::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);
        /** @var Attribute $meta */
        $meta = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_CLASS, $meta->flags);
    }

    #[Test]
    public function classIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(AsLlmProvider::class);

        self::assertTrue($reflection->isFinal(), 'AsLlmProvider must be final');
        self::assertTrue($reflection->isReadOnly(), 'AsLlmProvider must be readonly');
    }
}
