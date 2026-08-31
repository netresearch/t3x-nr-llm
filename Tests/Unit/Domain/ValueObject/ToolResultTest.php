<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

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

    #[Test]
    public function aSuccessfulResultCanNameTheRecordItWrote(): void
    {
        $result = ToolResult::text('Updated page [42]: title.')
            ->withWriteTarget(new RecordReference('pages', 42));

        self::assertInstanceOf(RecordReference::class, $result->writeTarget);
        self::assertSame('pages', $result->writeTarget->table);
        self::assertSame(42, $result->writeTarget->uid);
    }

    #[Test]
    public function aPlainResultNamesNoRecord(): void
    {
        self::assertNull(ToolResult::text('read something')->writeTarget);
        self::assertNull(ToolResult::error('nope')->writeTarget);
    }

    /**
     * Fail-closed, on the same terms as the artifact rule: a call that failed
     * must not report a record it wrote, or the observed outcome would count a
     * write that never happened.
     */
    #[Test]
    public function anErrorResultRefusesAWriteTarget(): void
    {
        $result = ToolResult::error('The update did not take.')
            ->withWriteTarget(new RecordReference('pages', 42));

        self::assertNull($result->writeTarget);
        self::assertTrue($result->isError);
    }

    /**
     * The regression this class exists to prevent (ADR-182): bounding used to be
     * a REBUILD from two properties, so anything else the result carried was
     * dropped on the way out of the loop. Adding a property must not reintroduce
     * that, which is why bounding is expressed as a transformation.
     */
    #[Test]
    public function boundingTheChannelsCarriesEveryOtherMemberForward(): void
    {
        $original = ToolResult::text('long content', new ToolArtifact(ArtifactType::TEXT, 'label', ['text' => 'x']))
            ->withWriteTarget(new RecordReference('sys_file_metadata', 137));

        $bounded = $original->withBoundedChannels('short', []);

        self::assertSame('short', $bounded->content);
        self::assertSame([], $bounded->artifacts);
        self::assertFalse($bounded->isError);
        self::assertInstanceOf(RecordReference::class, $bounded->writeTarget);
        self::assertSame('sys_file_metadata:137', (string)$bounded->writeTarget);
    }

    /**
     * Every property of the value object is either bounded or carried. A new one
     * added without a decision fails here rather than silently vanishing on the
     * way through the loop.
     */
    #[Test]
    public function boundingIsExhaustiveOverThePropertiesOfTheResult(): void
    {
        $properties = array_map(
            static fn(ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(ToolResult::class))->getProperties(),
        );
        sort($properties);

        self::assertSame(
            ['artifacts', 'content', 'isError', 'writeTarget'],
            $properties,
            'ToolResult gained a property. Decide in withBoundedChannels() whether it is bounded or carried '
            . 'forward — ADR-182 names three values already lost to a rebuild that answered for neither.',
        );
    }
}
