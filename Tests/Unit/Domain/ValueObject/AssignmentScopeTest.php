<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ScopedPage;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssignmentScope::class)]
#[CoversClass(ScopedPage::class)]
#[CoversClass(ScopeRefusal::class)]
#[CoversClass(AiActorContext::class)]
final class AssignmentScopeTest extends TestCase
{
    #[Test]
    public function aScopeSurvivesTheRoundTripThroughItsStoredForm(): void
    {
        $scope = new AssignmentScope(
            [new ScopedPage(7, 2), new ScopedPage(3)],
            [new RecordReference('sys_file', 9), new RecordReference('tt_content', 4)],
            ['tt_content', 'pages'],
            [1, 0],
        );

        $restored = AssignmentScope::fromArray($scope->toArray());

        self::assertInstanceOf(AssignmentScope::class, $restored);
        self::assertEquals($scope, $restored);
        self::assertSame([3, 7], array_map(static fn (ScopedPage $page): int => $page->uid, $restored->pages), 'pages are kept in order of uid');
        self::assertSame(['pages', 'tt_content'], $restored->tables);
        self::assertSame([0, 1], $restored->languages);
    }

    #[Test]
    public function aPageNamedTwiceKeepsItsWiderDepth(): void
    {
        $scope = new AssignmentScope([new ScopedPage(3, 1), new ScopedPage(3, 4), new ScopedPage(3, 2)]);

        self::assertCount(1, $scope->pages);
        self::assertSame(4, $scope->pages[0]->depth);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadable(): iterable
    {
        yield 'not an array' => ['pages'];
        yield 'no pages key' => [['records' => []]];
        yield 'a page without a depth' => [['pages' => [['uid' => 3]], 'records' => []]];
        yield 'a page with a string uid' => [['pages' => [['uid' => '3', 'depth' => 0]], 'records' => []]];
        yield 'a depth above the limit' => [['pages' => [['uid' => 3, 'depth' => 100]], 'records' => []]];
        yield 'a negative uid' => [['pages' => [['uid' => -3, 'depth' => 0]], 'records' => []]];
        yield 'a record without a table' => [['pages' => [], 'records' => [['uid' => 3]]]];
        yield 'a table that is no identifier' => [['pages' => [], 'records' => [['table' => 'pages; DROP', 'uid' => 3]]]];
        yield 'no tables key' => [['pages' => [], 'records' => [], 'languages' => null]];
        yield 'no languages key' => [['pages' => [], 'records' => [], 'tables' => null]];
        yield 'tables that are no list of names' => [['pages' => [], 'records' => [], 'tables' => [3], 'languages' => null]];
        yield 'languages that are no list of ints' => [['pages' => [], 'records' => [], 'tables' => null, 'languages' => ['1']]];
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function aStoredScopeThatCannotBeReadIsNoScope(mixed $stored): void
    {
        self::assertNull(AssignmentScope::fromArray($stored));
    }

    #[Test]
    public function aScopeThatNamesNothingAdmitsNothing(): void
    {
        self::assertTrue(AssignmentScope::none()->isEmpty());
        self::assertSame('nothing', AssignmentScope::none()->describe());
        self::assertFalse(AssignmentScope::forPage(3)->isEmpty());
    }

    #[Test]
    public function theLimitsOfASizeAreEnforced(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $scope = new AssignmentScope(array_map(static fn (int $uid): ScopedPage => new ScopedPage($uid), range(1, AssignmentScope::MAX_PAGES + 1)));

        self::fail('A scope of ' . count($scope->pages) . ' pages was accepted.');
    }

    #[Test]
    public function aRecordOfThePageTableIsAScopeOfThatPageAndAnyOtherIsAScopeOfOneRecord(): void
    {
        self::assertEquals(AssignmentScope::forPage(5), AssignmentScope::forRecord(new RecordReference('pages', 5)));
        self::assertEquals(new AssignmentScope([], [new RecordReference('tt_content', 5)]), AssignmentScope::forRecord(new RecordReference('tt_content', 5)));
    }

    #[Test]
    public function theDescriptionNamesUidsAndTablesOnly(): void
    {
        $scope = new AssignmentScope([new ScopedPage(2, 1), new ScopedPage(9)], [new RecordReference('tt_content', 4)]);

        self::assertSame('page 2 and 1 level below it, page 9, tt_content:4', $scope->describe());
    }

    #[Test]
    public function aRefusalSurvivesItsStoredFormAndAGarbledOneIsDropped(): void
    {
        $refusal = new ScopeRefusal('create', 'tt_content', 0, 5, ScopeRefusal::OUTSIDE_SCOPE);

        self::assertEquals($refusal, ScopeRefusal::tryFromArray($refusal->toArray()));
        self::assertSame('tt_content (on page 5)', $refusal->subject());
        self::assertNull(ScopeRefusal::tryFromArray(['operation' => 'create', 'table' => 'tt_content', 'uid' => 0, 'pid' => 5, 'reason' => 'invented']));
        self::assertNull(ScopeRefusal::tryFromArray(['operation' => 'create']));
    }

    #[Test]
    public function anActorWithoutAScopeStoresNoScopeKeyAndKeepsItsStoredForm(): void
    {
        $actor = AiActorContext::backendUser(9, false, [3], [], 2);

        self::assertArrayNotHasKey('assignmentScope', $actor->toArray());
        self::assertNull(AiActorContext::fromArray($actor->toArray())->assignmentScope);
    }

    #[Test]
    public function anActorKeepsItsScopeThroughTheQueueAndThroughRebuildsThatKeepTheRest(): void
    {
        $scope = new AssignmentScope([new ScopedPage(2, 1)], [new RecordReference('tt_content', 4)], ['tt_content'], [0]);
        $actor = AiActorContext::backendUser(9, false, [3], [], 2)->withAssignmentScope($scope);

        $restored = AiActorContext::fromArray($actor->toArray());

        self::assertEquals($scope, $restored->assignmentScope);
        self::assertSame(2, $restored->workspaceId, 'binding a scope leaves the rest of the actor as it was');
        self::assertSame(9, $restored->backendUserUid);
        self::assertNull($restored->withAssignmentScope(null)->assignmentScope);
    }

    /**
     * The three states stay apart: no key is no scope, a readable key is the
     * scope, and an unreadable key admits nothing. Reading it as no scope would
     * widen the run to everything.
     */
    #[Test]
    public function aStoredScopeThatCannotBeReadBindsTheActorToNothing(): void
    {
        $stored                    = AiActorContext::backendUser(9)->toArray();
        $stored['assignmentScope'] = ['pages' => 'garbage'];

        $actor = AiActorContext::fromArray($stored);

        self::assertInstanceOf(AssignmentScope::class, $actor->assignmentScope);
        self::assertTrue($actor->assignmentScope->isEmpty());
    }

    #[Test]
    public function anEmptyScopeIsKeptAsAnEmptyScopeAndNotAsNone(): void
    {
        $actor = AiActorContext::backendUser(9)->withAssignmentScope(AssignmentScope::none());

        $restored = AiActorContext::fromArray($actor->toArray());

        self::assertInstanceOf(AssignmentScope::class, $restored->assignmentScope);
        self::assertTrue($restored->assignmentScope->isEmpty());
    }
}
