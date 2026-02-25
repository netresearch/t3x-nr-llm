<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\ImageGenerationException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[CoversClass(ImageGenerationException::class)]
class ImageGenerationExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsPropertiesCorrectly(): void
    {
        $exception = new ImageGenerationException(
            'Content policy violation',
            'image',
            ['reason' => 'inappropriate_content'],
        );

        self::assertEquals('Content policy violation', $exception->getMessage());
        self::assertEquals('image', $exception->service);
        self::assertEquals(['reason' => 'inappropriate_content'], $exception->context);
    }

    #[Test]
    public function exceptionWithCodeAndPreviousWorks(): void
    {
        $previous = new RuntimeException('Original error');
        $exception = new ImageGenerationException(
            'Generation failed',
            'image',
            ['model' => 'dall-e-3'],
            500,
            $previous,
        );

        self::assertEquals('Generation failed', $exception->getMessage());
        self::assertEquals(500, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
