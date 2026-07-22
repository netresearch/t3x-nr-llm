<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversClass(ToolResult::class)]
final class ToolResultTest extends TestCase
{
    #[Test]
    public function textIsANonErrorResultWithNoArtifactsByDefault(): void
    {
        $result = ToolResult::text('hello');

        self::assertSame('hello', $result->content);
        self::assertFalse($result->isError);
        self::assertSame([], $result->artifacts);
    }

    #[Test]
    public function textPreservesArtifactOrder(): void
    {
        $a = new ToolArtifact(ArtifactType::TABLE, 'first', ['columns' => [], 'rows' => []]);
        $b = new ToolArtifact(ArtifactType::TEXT, 'second', ['text' => 'x']);

        $result = ToolResult::text('content', $a, $b);

        self::assertSame([$a, $b], $result->artifacts);
        self::assertFalse($result->isError);
    }

    #[Test]
    public function errorIsFailClosed_flagsErrorAndCarriesNoArtifacts(): void
    {
        $result = ToolResult::error('Error: tool "x" failed.');

        self::assertSame('Error: tool "x" failed.', $result->content);
        self::assertTrue($result->isError);
        self::assertSame([], $result->artifacts);
    }

    #[Test]
    public function contentIsTheOnlyStringPath_noToStringOrWireAccessorExists(): void
    {
        $reflection = new ReflectionClass(ToolResult::class);

        // Egress separation by construction: no __toString() that could merge an
        // artifact into a wire string, and the constructor is private so the
        // fail-closed invariant cannot be bypassed.
        self::assertFalse($reflection->hasMethod('__toString'));
        self::assertTrue($reflection->getConstructor()?->isPrivate());

        // The only string-typed public member is `content`.
        $stringProps = [];
        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === 'string') {
                $stringProps[] = $property->getName();
            }
        }
        self::assertSame(['content'], $stringProps);
    }
}
