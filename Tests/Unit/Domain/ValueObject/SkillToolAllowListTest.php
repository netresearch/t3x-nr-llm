<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillToolAllowList::class)]
final class SkillToolAllowListTest extends TestCase
{
    #[Test]
    public function anUnrestrictedSideImposesNothing(): void
    {
        $declared = new SkillToolAllowList(['read_a']);
        $open     = new SkillToolAllowList(null);

        self::assertSame(['read_a'], $declared->intersect($open)->toolNames);
        self::assertSame(['read_a'], $open->intersect($declared)->toolNames);
        self::assertNull($open->intersect(new SkillToolAllowList(null))->toolNames);
    }

    #[Test]
    public function twoListsKeepOnlyWhatBothAdmit(): void
    {
        $stored = new SkillToolAllowList(['approve', 'read_a', 'read_b']);

        self::assertSame(['read_a'], $stored->intersect(new SkillToolAllowList(['read_a', 'read_c']))->toolNames);
        self::assertSame([], $stored->intersect(new SkillToolAllowList([]))->toolNames);
    }

    /**
     * A process binding survives the stored form and either side of an
     * intersection, unrestricted sides included; an unbound list stays
     * unbound and keeps its stored form.
     */
    #[Test]
    public function aProcessBindingSurvivesStorageAndIntersection(): void
    {
        $bound = new SkillToolAllowList(['read_a'], true);

        self::assertTrue(SkillToolAllowList::fromStored($bound->toStored())?->processBound);
        self::assertTrue(SkillToolAllowList::fromStored((new SkillToolAllowList(null, true))->toStored())?->processBound);
        self::assertTrue((new SkillToolAllowList(null))->intersect($bound)->processBound);
        self::assertTrue($bound->intersect(new SkillToolAllowList(null))->processBound);
        self::assertTrue((new SkillToolAllowList(['read_a']))->intersect($bound)->processBound);

        $unbound = new SkillToolAllowList(['read_a']);

        self::assertSame(['toolNames' => ['read_a']], $unbound->toStored());
        self::assertFalse(SkillToolAllowList::fromStored($unbound->toStored())?->processBound);
        self::assertFalse($unbound->intersect(new SkillToolAllowList(null))->processBound);
    }

    /**
     * An invoked process declares its tools into the run's union (ADR-228):
     * on an unrestricted run its declaration becomes the restriction, as an
     * invoked declaring skill does in the resolver, and on a restricted one
     * it widens the union by exactly its own names.
     */
    #[Test]
    public function aDeclarationJoinsTheUnion(): void
    {
        self::assertSame(['read_a'], (new SkillToolAllowList(null))->withDeclaration(['read_a'])->toolNames);
        self::assertSame([], (new SkillToolAllowList(null))->withDeclaration([])->toolNames, 'a process declaring nothing restricts to nothing');
        self::assertSame(['read_b', 'read_a'], (new SkillToolAllowList(['read_b']))->withDeclaration(['read_a', 'read_b'])->toolNames);
        self::assertTrue((new SkillToolAllowList(['read_b']))->withDeclaration([])->processBound, 'the run holds a process');
        self::assertFalse((new SkillToolAllowList(['read_b']))->processBound);
    }
}
