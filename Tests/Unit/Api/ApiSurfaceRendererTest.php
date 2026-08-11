<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api;

use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AddedMethod;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\InheritsForeignConstructor;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\InheritsOwnConstructor;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\NarrowConstructor;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\WidenedConstructor;
use Netresearch\NrLlm\Tests\Unit\Api\Support\ApiSurfaceDiff;
use Netresearch\NrLlm\Tests\Unit\Api\Support\ApiSurfaceRenderer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Proves what ApiSurfaceSnapshotTest cannot prove about itself.
 *
 * A snapshot assertion only ever shows that today's surface equals the
 * committed one. It cannot show that a given break WOULD have been caught —
 * and the constructor break was not: `api-surface.txt` contained no
 * constructor at all, so a new required argument on an `@api` class passed
 * the gate in silence.
 *
 * These tests run the renderer and the classifier against fixture classes
 * that differ in one property each, so the claim "the snapshot catches a
 * widened constructor" is checked rather than asserted.
 */
#[CoversNothing] // both subjects live under Tests/, never a coverage target
final class ApiSurfaceRendererTest extends TestCase
{
    #[Test]
    public function aWidenedConstructorIsTheOnlyDifferenceTheRendererReports(): void
    {
        $renderer = new ApiSurfaceRenderer();

        $narrow  = $this->linesOf($renderer->render([NarrowConstructor::class]));
        $widened = $this->linesOf($renderer->render([WidenedConstructor::class]));

        // Everything but the constructor is identical between the two
        // fixtures — that is what makes the next assertion mean something.
        self::assertSame(
            $this->withoutConstructor($narrow),
            $this->withoutConstructor($widened),
            'The fixtures must differ in the constructor and nothing else.',
        );

        self::assertNotSame(
            $narrow,
            $widened,
            'A new required constructor argument left the rendered surface unchanged — '
            . 'the snapshot is blind to constructor breaks again.',
        );

        self::assertSame(
            ['constructor(string $name)'],
            $this->constructorLines($narrow),
        );
        self::assertSame(
            ['constructor(string $name, int $limit)'],
            $this->constructorLines($widened),
        );
    }

    #[Test]
    public function aWidenedConstructorClassifiesAsBreaking(): void
    {
        $diff = $this->diffBetween(NarrowConstructor::class, WidenedConstructor::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame([], $diff->added);
        self::assertSame([], $diff->removed);
        self::assertCount(1, $diff->changed);
        self::assertSame(
            WidenedConstructor::class . ' :: constructor',
            $diff->changed[0]['entry'],
        );
        self::assertStringContainsString('deprecate first, remove later', $diff->describe());
        self::assertStringStartsWith(strtoupper($diff->verdict()) . ' — ', $diff->describe());
    }

    #[Test]
    public function anAddedMethodClassifiesAsAdditive(): void
    {
        $diff = $this->diffBetween(NarrowConstructor::class, AddedMethod::class);

        self::assertSame('additive', $diff->verdict());
        self::assertSame([], $diff->removed);
        self::assertSame([], $diff->changed);
        self::assertSame(
            [AddedMethod::class . ' :: method shout(): string'],
            $diff->added,
        );
        self::assertStringContainsString('regenerate the snapshot', $diff->describe());
        self::assertStringStartsWith(strtoupper($diff->verdict()) . ' — ', $diff->describe());
    }

    #[Test]
    public function aRemovedMethodClassifiesAsBreaking(): void
    {
        $diff = $this->diffBetween(AddedMethod::class, NarrowConstructor::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame([], $diff->added);
        self::assertSame([], $diff->changed);
        self::assertSame(
            [NarrowConstructor::class . ' :: method shout(): string'],
            $diff->removed,
        );
    }

    #[Test]
    public function aConstructorInheritedFromAnOwnNamespaceBaseIsRecorded(): void
    {
        $rendered = (new ApiSurfaceRenderer())->render([InheritsOwnConstructor::class]);

        self::assertSame(
            ['constructor(string $endpoint)'],
            $this->constructorLines($this->linesOf($rendered)),
            'A class that declares no constructor still gets one from its own-namespace base, '
            . 'and `new` binds to that — leaving it out is how a required argument added to the '
            . 'base passes the gate in silence.',
        );
    }

    #[Test]
    public function aConstructorInheritedFromOutsideTheRepositoryIsNotRecorded(): void
    {
        $rendered = (new ApiSurfaceRenderer())->render([InheritsForeignConstructor::class]);

        self::assertSame(
            [],
            $this->constructorLines($this->linesOf($rendered)),
            'A constructor inherited from TYPO3 core or the SPL is not ours and differs between '
            . 'the 13.4 and 14.x legs; recording it would make the snapshot matrix-dependent.',
        );
    }

    #[Test]
    public function anUnchangedSurfaceProducesNoDiff(): void
    {
        $renderer = new ApiSurfaceRenderer();
        $rendered = $renderer->render([NarrowConstructor::class, AddedMethod::class]);

        $diff = ApiSurfaceDiff::between($rendered, $rendered);

        self::assertTrue($diff->isEmpty());
        self::assertSame('identical', $diff->verdict());
    }

    /**
     * Renders both fixtures and rewrites the first one's class name to the
     * second's, so the diff sees ONE class whose members moved rather than
     * one class removed and another added. Deriving the "before" side from a
     * real rendering keeps this test honest if the rendering format changes.
     *
     * @param class-string $before
     * @param class-string $after
     */
    private function diffBetween(string $before, string $after): ApiSurfaceDiff
    {
        $renderer = new ApiSurfaceRenderer();

        return ApiSurfaceDiff::between(
            str_replace($before, $after, $renderer->render([$before])),
            $renderer->render([$after]),
        );
    }

    /**
     * @return list<string> the member lines of a single-class rendering, untrimmed of nothing but indentation
     */
    private function linesOf(string $rendered): array
    {
        $lines = array_map(trim(...), explode("\n", trim($rendered)));

        // Drop the `FQCN (class)` header — the fixtures have different names
        // by construction, and that is not the difference under test.
        return array_values(array_slice($lines, 1));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function withoutConstructor(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn(string $line): bool => !str_starts_with($line, 'constructor('),
        ));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function constructorLines(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn(string $line): bool => str_starts_with($line, 'constructor('),
        ));
    }
}
