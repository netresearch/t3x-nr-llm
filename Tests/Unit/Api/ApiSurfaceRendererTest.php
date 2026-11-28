<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api;

use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBase;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseImplementingLoggerAware;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseImplementingPort;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseWithAbstractMethod;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseWithConcreteMethod;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseWithFinalKind;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseWithProtectedAbstract;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseWithProtectedKind;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseWithPublicKind;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AbstractBaseWithStaticAbstract;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\AddedMethod;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\BackedFixtureEnum;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\ConcreteBase;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\ConcreteBaseWithFinalKind;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\ConcreteBaseWithProtectedKind;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\ConcreteBaseWithPublicKind;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\CountableBase;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\ExtendingPort;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\FinalConcreteBase;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\InheritsForeignConstructor;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\InheritsOwnConstructor;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\NarrowConstructor;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\StandalonePort;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\TraitWithoutPrivateAbstract;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\TraitWithPrivateAbstract;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\TraitWithProtectedAbstract;
use Netresearch\NrLlm\Tests\Unit\Api\Fixtures\WidenedConstructor;
use Netresearch\NrLlm\Tests\Unit\Api\Support\ApiSurfaceDiff;
use Netresearch\NrLlm\Tests\Unit\Api\Support\ApiSurfaceRenderer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;

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
    public function aBackedEnumRendersItsBackingValues(): void
    {
        $rendered = (new ApiSurfaceRenderer())->render([BackedFixtureEnum::class]);

        // The engine-declared enum members (cases/from/tryFrom, name/value)
        // reflect as DECLARED on the enum itself and are therefore rendered —
        // the committed snapshot has carried them across the whole matrix
        // since its first version, so they are matrix-stable.
        self::assertSame(
            [
                'case Read = "read"',
                'case Write = "write"',
                'method static cases(): array',
                'method static from(int|string $value): static',
                'method static tryFrom(int|string $value): ?static',
                'property readonly name: string',
                'property readonly value: string',
            ],
            $this->linesOf($rendered),
            'The backing value is the frozen vocabulary — a name-only line would let a value change pass green.',
        );
    }

    #[Test]
    public function aChangedBackingValueClassifiesAsBreaking(): void
    {
        $renderer = new ApiSurfaceRenderer();
        $rendered = $renderer->render([BackedFixtureEnum::class]);

        // Simulate the value change textually: same case name, new value —
        // exactly the shape of a `case Read = 'read'` → `= 'reveal'` edit.
        $diff = ApiSurfaceDiff::between($rendered, str_replace('"read"', '"reveal"', $rendered));

        self::assertSame('breaking', $diff->verdict());
        self::assertCount(1, $diff->changed);
        self::assertSame(BackedFixtureEnum::class . ' :: case Read', $diff->changed[0]['entry']);
        self::assertSame('case Read = "read"', $diff->changed[0]['was']);
        self::assertSame('case Read = "reveal"', $diff->changed[0]['now']);
    }

    #[Test]
    public function aNewAbstractMethodOnAnAbstractClassClassifiesAsBreaking(): void
    {
        // A subclass outside the package has to implement it: the
        // AbstractOptions / AbstractModelDiscoverer case.
        $diff = $this->diffBetween(AbstractBase::class, AbstractBaseWithAbstractMethod::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame(
            [AbstractBaseWithAbstractMethod::class . ' :: method abstract kind(): string'],
            $diff->addedToContracts,
        );
    }

    #[Test]
    public function aNewConcreteMethodOnAnAbstractClassStaysAdditive(): void
    {
        $diff = $this->diffBetween(AbstractBase::class, AbstractBaseWithConcreteMethod::class);

        self::assertSame('additive', $diff->verdict());
        self::assertStringStartsWith(
            AbstractBaseWithConcreteMethod::class . ' (abstract class)',
            (new ApiSurfaceRenderer())->render([AbstractBaseWithConcreteMethod::class]),
        );
    }

    #[Test]
    public function anInterfaceThatStartsToExtendAnotherClassifiesAsBreaking(): void
    {
        $diff = $this->diffBetween(StandalonePort::class, ExtendingPort::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame(ExtendingPort::class . ' :: (declaration)', $diff->changed[0]['entry']);
        self::assertSame(ExtendingPort::class . ' (interface) extends Countable', $diff->changed[0]['now']);
    }

    #[Test]
    public function aNewProtectedAbstractMethodClassifiesAsBreaking(): void
    {
        // The AbstractSpecializedService pattern: subclasses implement
        // protected hooks, so a new one breaks every subclass outside nr_llm.
        $diff = $this->diffBetween(AbstractBase::class, AbstractBaseWithProtectedAbstract::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame(
            [AbstractBaseWithProtectedAbstract::class . ' :: method abstract protected kind(): string'],
            $diff->addedToContracts,
        );
    }

    #[Test]
    public function anAbstractClassThatImplementsAnInterfaceItLeavesOpenClassifiesAsBreaking(): void
    {
        $diff = $this->diffBetween(AbstractBase::class, AbstractBaseImplementingPort::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame(
            [AbstractBaseImplementingPort::class . ' :: method abstract send(string $text): void'],
            $diff->addedToContracts,
        );
        self::assertStringContainsString(
            ' implements ' . StandalonePort::class,
            (new ApiSurfaceRenderer())->render([AbstractBaseImplementingPort::class]),
        );
    }

    #[Test]
    public function aConcreteClassThatAddsAnImplementedInterfaceStaysAdditive(): void
    {
        $diff = $this->diffBetween(ConcreteBase::class, CountableBase::class);

        self::assertSame('additive', $diff->verdict());
        self::assertSame(CountableBase::class . ' (class) implements Countable', $diff->widened[0]['now']);
    }

    #[Test]
    public function aClassThatDropsAnImplementedInterfaceClassifiesAsBreaking(): void
    {
        $diff = $this->diffBetween(CountableBase::class, ConcreteBase::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame(ConcreteBase::class . ' :: (declaration)', $diff->changed[0]['entry']);
    }

    #[Test]
    public function anAbstractClassThatBecomesConcreteStaysAdditive(): void
    {
        $diff = $this->diffBetween(AbstractBase::class, ConcreteBase::class);

        self::assertSame('additive', $diff->verdict());
        self::assertSame([], $diff->changed);
    }

    #[Test]
    public function anAbstractClassMadeConcreteThatImplementsItsProtectedHookNeedsACheckByHand(): void
    {
        // A concrete class's protected methods are not rendered, so an
        // implemented hook and a deleted one look the same.
        $diff = $this->diffBetween(AbstractBaseWithProtectedAbstract::class, ConcreteBaseWithProtectedKind::class);

        self::assertSame('check by hand', $diff->verdict());
        self::assertFalse($diff->isAdditiveOnly());
        self::assertSame([], $diff->removed);
        self::assertSame([], $diff->changed);
        self::assertSame(
            [ConcreteBaseWithProtectedKind::class . ' :: method abstract protected kind(): string'],
            $diff->checkByHand,
        );
    }

    #[Test]
    public function anAbstractClassMadeConcreteThatDeletedItsProtectedHookIsNotAdditive(): void
    {
        $diff = $this->diffBetween(AbstractBaseWithProtectedAbstract::class, ConcreteBase::class);

        self::assertSame('check by hand', $diff->verdict());
        self::assertFalse($diff->isAdditiveOnly());
        self::assertStringContainsString('cannot tell given a body from deleted', $diff->describe());
    }

    #[Test]
    public function anAbstractClassThatGivesAnAbstractMethodABodyStaysAdditive(): void
    {
        $diff = $this->diffBetween(AbstractBaseWithAbstractMethod::class, AbstractBaseWithPublicKind::class);

        self::assertSame('additive', $diff->verdict());
        self::assertSame([], $diff->changed);
        self::assertSame('method kind(): string', $diff->widened[0]['now']);
    }

    #[Test]
    public function anAbstractClassThatGivesItsProtectedHookABodyNeedsACheckByHand(): void
    {
        $diff = $this->diffBetween(AbstractBaseWithProtectedAbstract::class, AbstractBaseWithProtectedKind::class);

        self::assertSame('check by hand', $diff->verdict());
        self::assertSame([], $diff->removed);
        self::assertSame(
            [AbstractBaseWithProtectedKind::class . ' :: method abstract protected kind(): string'],
            $diff->checkByHand,
        );
        self::assertStringContainsString('cannot tell given a body from deleted', $diff->describe());
    }

    #[Test]
    public function anAbstractClassThatAddsAThirdPartyInterfaceNeedsACheckByHand(): void
    {
        // Methods declared outside nr_llm or PHP are not rendered, so the
        // setLogger() every subclass now has to implement is invisible.
        $diff = $this->diffBetween(AbstractBase::class, AbstractBaseImplementingLoggerAware::class);

        self::assertSame('check by hand', $diff->verdict());
        self::assertFalse($diff->isAdditiveOnly());
        self::assertSame([], $diff->widened);
        self::assertStringContainsString(LoggerAwareInterface::class, $diff->checkByHand[0]);
    }

    #[Test]
    public function anAbstractMethodMadeFinalClassifiesAsBreaking(): void
    {
        // A final body breaks every subclass that implemented the method.
        $diff = $this->diffBetween(AbstractBaseWithAbstractMethod::class, AbstractBaseWithFinalKind::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame('method final kind(): string', $diff->changed[0]['now']);
    }

    #[Test]
    public function aConcreteMethodMadeFinalClassifiesAsBreaking(): void
    {
        $diff = $this->diffBetween(ConcreteBaseWithPublicKind::class, ConcreteBaseWithFinalKind::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame('method kind(): string', $diff->changed[0]['was']);
        self::assertSame('method final kind(): string', $diff->changed[0]['now']);
    }

    #[Test]
    public function aNewPrivateAbstractMethodOfATraitClassifiesAsBreaking(): void
    {
        self::assertStringContainsString(
            '  method abstract private secret(): string',
            (new ApiSurfaceRenderer())->render([TraitWithPrivateAbstract::class]),
        );

        $diff = $this->diffBetween(TraitWithoutPrivateAbstract::class, TraitWithPrivateAbstract::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame(
            [TraitWithPrivateAbstract::class . ' :: method abstract private secret(): string'],
            $diff->addedToContracts,
        );
    }

    #[Test]
    public function aProtectedAbstractMethodOfATraitIsRendered(): void
    {
        self::assertStringContainsString(
            '  method abstract protected kind(): string',
            (new ApiSurfaceRenderer())->render([TraitWithProtectedAbstract::class]),
        );
    }

    #[Test]
    public function anAbstractClassMadeConcreteWhoseMethodOnlyLosesAbstractStaysAdditive(): void
    {
        $diff = $this->diffBetween(AbstractBaseWithAbstractMethod::class, ConcreteBaseWithPublicKind::class);

        self::assertSame('additive', $diff->verdict());
        self::assertSame([], $diff->changed);
    }

    #[Test]
    public function aStaticAbstractMethodIsRenderedStatic(): void
    {
        self::assertStringContainsString(
            '  method abstract protected static make(): string',
            (new ApiSurfaceRenderer())->render([AbstractBaseWithStaticAbstract::class]),
        );
    }

    #[Test]
    public function aClassMadeFinalClassifiesAsBreaking(): void
    {
        $diff = $this->diffBetween(ConcreteBase::class, FinalConcreteBase::class);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame(FinalConcreteBase::class . ' (final class)', $diff->changed[0]['now']);
    }

    #[Test]
    public function aFinalClassMadeNonFinalStaysAdditive(): void
    {
        $diff = $this->diffBetween(FinalConcreteBase::class, ConcreteBase::class);

        self::assertSame('additive', $diff->verdict());
        self::assertSame(ConcreteBase::class . ' (class)', $diff->widened[0]['now']);
    }

    #[Test]
    public function aConcreteClassThatBecomesAbstractClassifiesAsBreaking(): void
    {
        $diff = $this->diffBetween(ConcreteBase::class, AbstractBase::class);

        self::assertSame('breaking', $diff->verdict());
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
            static fn (string $line): bool => !str_starts_with($line, 'constructor('),
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
            static fn (string $line): bool => str_starts_with($line, 'constructor('),
        ));
    }
}
