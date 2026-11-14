<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ScopedPage;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeChecker;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedDestination;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Who is in scope, on the page tree of {@see ArrayRecordTopology}.
 */
#[CoversClass(AssignmentScopeChecker::class)]
#[CoversClass(AssignmentScope::class)]
final class AssignmentScopeCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function records(): iterable
    {
        // The scope is page 2 and one level below it: pages 2, 3 and 6.
        yield 'content on the scoped page' => ['tt_content', 21, true];
        yield 'content on a page one level below' => ['tt_content', 22, true];
        yield 'the scoped page itself' => ['pages', 2, true];
        yield 'a page one level below' => ['pages', 3, true];
        yield 'the German overlay of a page in scope is the page' => ['pages', 13, true];
        yield 'a translation of content in scope' => ['tt_content', 31, true];
        yield 'a page two levels below' => ['pages', 4, false];
        yield 'content on a page two levels below' => ['tt_content', 24, false];
        yield 'another branch of the tree' => ['pages', 5, false];
        yield 'content on another branch' => ['tt_content', 23, false];
        yield 'a translation of content on another branch' => ['tt_content', 33, false];
        yield 'a record at the root of the installation' => ['tt_content', 25, false];
        yield 'the site root above the scope' => ['pages', 1, false];
        yield 'a record that does not exist' => ['tt_content', 999, false];
    }

    #[Test]
    #[DataProvider('records')]
    public function aRecordIsInScopeWhenItLiesOnAScopedPage(string $table, int $uid, bool $allowed): void
    {
        $refusal = $this->checker()->recordRefusal(AssignmentScope::forPage(2, 1), 'update', $table, $uid, 0);

        self::assertSame($allowed, !$refusal instanceof ScopeRefusal, (string) $refusal?->reason);
    }

    #[Test]
    public function aMissingRecordIsUnresolvableAndNotOutsideTheScope(): void
    {
        self::assertSame(ScopeRefusal::UNRESOLVABLE, $this->checker()->recordRefusal(AssignmentScope::forPage(2, 1), 'update', 'tt_content', 999, 0)?->reason);
        self::assertSame(ScopeRefusal::OUTSIDE_SCOPE, $this->checker()->recordRefusal(AssignmentScope::forPage(2, 1), 'update', 'tt_content', 23, 0)?->reason);
    }

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function destinations(): iterable
    {
        yield 'content on the scoped page' => ['tt_content', 2, true];
        yield 'content on the last page in scope' => ['tt_content', 3, true];
        yield 'content on a page too deep' => ['tt_content', 4, false];
        yield 'content on another branch' => ['tt_content', 5, false];
        yield 'content at the root' => ['tt_content', 0, false];
        yield 'a page under the scoped page' => ['pages', 2, true];
        yield 'a page under the last level in scope would itself be out of it' => ['pages', 3, false];
        yield 'a page under another branch' => ['pages', 5, false];
    }

    #[Test]
    #[DataProvider('destinations')]
    public function aNewRowIsInScopeWhenItsPageIsAndAPageNeedsOneLevelMore(string $table, int $pid, bool $allowed): void
    {
        $refusal = $this->checker()->destinationRefusal(AssignmentScope::forPage(2, 1), 'create', $table, $pid, 0);

        self::assertSame($allowed, !$refusal instanceof ScopeRefusal);
        if (!$allowed) {
            self::assertSame(ScopeRefusal::OUTSIDE_SCOPE, $refusal?->reason);
            self::assertSame(0, $refusal->uid);
            self::assertSame($pid, $refusal->pid);
        }
    }

    #[Test]
    public function aScopeOfOnePageAdmitsThePageAndItsRecordsOnly(): void
    {
        $scope = AssignmentScope::forPage(2);

        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'pages', 2, 0));
        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'tt_content', 21, 0));
        self::assertNotNull($this->checker()->recordRefusal($scope, 'update', 'pages', 3, 0));
        self::assertNotNull($this->checker()->recordRefusal($scope, 'update', 'tt_content', 22, 0));
        self::assertNotNull($this->checker()->destinationRefusal($scope, 'create', 'pages', 2, 0), 'a subpage would lie outside');
        self::assertNull($this->checker()->destinationRefusal($scope, 'create', 'tt_content', 2, 0));
    }

    #[Test]
    public function theScopeThatAdmitsNothingRefusesEverything(): void
    {
        $none = AssignmentScope::none();

        self::assertNotNull($this->checker()->recordRefusal($none, 'update', 'tt_content', 21, 0));
        self::assertNotNull($this->checker()->recordRefusal($none, 'update', 'pages', 2, 0));
        self::assertNotNull($this->checker()->destinationRefusal($none, 'create', 'tt_content', 2, 0));
    }

    #[Test]
    public function aRecordScopeAdmitsTheRecordAndItsTranslationsAndNothingElse(): void
    {
        $scope = AssignmentScope::forRecord(new RecordReference('tt_content', 23));

        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'tt_content', 23, 0));
        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'tt_content', 33, 0), 'the translation of a scoped record');
        self::assertNotNull($this->checker()->recordRefusal($scope, 'update', 'tt_content', 21, 0));
        self::assertNotNull($this->checker()->recordRefusal($scope, 'update', 'pages', 5, 0), 'its page is not in scope');
        self::assertNotNull($this->checker()->destinationRefusal($scope, 'create', 'tt_content', 5, 0), 'a record scope puts nothing new on its page');
    }

    #[Test]
    public function theMetadataOfAScopedFileIsInScopeAndThatOfAnotherFileIsNot(): void
    {
        $scope = AssignmentScope::forRecord(new RecordReference('sys_file', 90));

        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'sys_file', 90, 0));
        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'sys_file_metadata', 91, 0));
        self::assertNotNull($this->checker()->recordRefusal($scope, 'update', 'sys_file_metadata', 92, 0));
        self::assertNotNull($this->checker()->recordRefusal($scope, 'update', 'sys_file', 99, 0));
    }

    #[Test]
    public function theTablesOfAScopeNarrowWhatItAdmits(): void
    {
        $scope = new AssignmentScope([new ScopedPage(2, 1)], [], ['tt_content']);

        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'tt_content', 21, 0));
        self::assertSame(ScopeRefusal::TABLE_NOT_ALLOWED, $this->checker()->recordRefusal($scope, 'update', 'pages', 2, 0)?->reason);
        self::assertSame(ScopeRefusal::TABLE_NOT_ALLOWED, $this->checker()->destinationRefusal($scope, 'create', 'pages', 2, 0)?->reason);
        self::assertNull($this->checker()->destinationRefusal($scope, 'create', 'tt_content', 2, 0));
    }

    #[Test]
    public function theLanguagesOfAScopeNarrowWhatItAdmitsAndATranslationIsJudgedByItsTargetLanguage(): void
    {
        $scope = new AssignmentScope([new ScopedPage(2, 1)], [], null, [1]);

        self::assertSame(ScopeRefusal::LANGUAGE_NOT_ALLOWED, $this->checker()->recordRefusal($scope, 'update', 'tt_content', 21, 0)?->reason, 'the default language is not listed');
        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'tt_content', 31, 0), 'the German translation is');

        $translate = new ScopedWrite('translate', [new RecordReference('tt_content', 21)], [], false, 1);
        self::assertSame([], $this->checker()->refusalsFor($scope, $translate, 0), 'the source is in the default language and may be translated into a listed one');

        $elsewhere = new ScopedWrite('translate', [new RecordReference('tt_content', 21)], [], false, 2);
        self::assertSame([ScopeRefusal::LANGUAGE_NOT_ALLOWED], array_map(static fn (ScopeRefusal $refusal): string => $refusal->reason, $this->checker()->refusalsFor($scope, $elsewhere, 0)));
    }

    #[Test]
    public function aPageCommandThatReachesSubpagesNeedsTheWholeSubtreeInScope(): void
    {
        $shallow = AssignmentScope::forPage(2, 1);
        $deep    = AssignmentScope::forPage(2, 2);

        $delete  = new ScopedWrite('delete', [new RecordReference('pages', 3)], [], true);
        $refused = $this->checker()->refusalsFor($shallow, $delete, 0);
        self::assertSame([ScopeRefusal::SUBTREE_OUTSIDE_SCOPE], array_map(static fn (ScopeRefusal $refusal): string => $refusal->reason, $refused));
        self::assertSame(4, $refused[0]->uid, 'the page that lies outside is named');
        self::assertSame([], $this->checker()->refusalsFor($deep, $delete, 0));

        $leaf = new ScopedWrite('delete', [new RecordReference('pages', 6)], [], true);
        self::assertSame([], $this->checker()->refusalsFor($shallow, $leaf, 0), 'a page with no subpages');

        $reaches = new ScopedWrite('delete', [new RecordReference('pages', 3)], [], false);
        self::assertSame([], $this->checker()->refusalsFor($shallow, $reaches, 0), 'a command that does not reach subpages is judged on the page');
    }

    /**
     * A moved page takes its subpages along, so the deepest of them has to lie
     * in scope at the new position. Page 3 has page 4 below it.
     */
    #[Test]
    public function aMovedPageNeedsItsDeepestSubpageInScopeAtTheNewPosition(): void
    {
        $move = new ScopedWrite('move', [new RecordReference('pages', 3)], [new ScopedDestination('pages', 2)], true);

        self::assertSame(
            [ScopeRefusal::OUTSIDE_SCOPE],
            array_map(static fn (ScopeRefusal $refusal): string => $refusal->reason, $this->checker()->refusalsFor(AssignmentScope::forPage(2, 1), $move, 0)),
            'the page fits under page 2 in a scope of depth 1, its subpage would be two levels down',
        );
        self::assertSame([], $this->checker()->refusalsFor(AssignmentScope::forPage(2, 2), $move, 0));

        $leaf = new ScopedWrite('move', [new RecordReference('pages', 6)], [new ScopedDestination('pages', 3)], true);
        self::assertNotSame([], $this->checker()->refusalsFor(AssignmentScope::forPage(2, 1), $leaf, 0), 'under page 3 the page would be two levels down');
        self::assertSame([], $this->checker()->refusalsFor(AssignmentScope::forPage(2, 2), $leaf, 0));

        $outside = new ScopedWrite('move', [new RecordReference('pages', 3)], [new ScopedDestination('pages', 5)], true);
        self::assertNotSame([], $this->checker()->refusalsFor(AssignmentScope::forPage(2, 5), $outside, 0), 'under an out-of-scope parent');
    }

    #[Test]
    public function aMovedPageBehindAnotherPageIsJudgedAtThatPagesParent(): void
    {
        $behind = new ScopedWrite('move', [new RecordReference('pages', 3), new RecordReference('pages', 6)], [], true);

        self::assertNotSame([], $this->checker()->refusalsFor(AssignmentScope::forPage(2, 1), $behind, 0), 'the subtree of page 3 would be one level too deep behind page 6');
        self::assertSame([], $this->checker()->refusalsFor(AssignmentScope::forPage(2, 2), $behind, 0));
    }

    #[Test]
    public function aNewRowInTheDefaultLanguageNeedsThatLanguageInTheList(): void
    {
        $scope  = new AssignmentScope([new ScopedPage(2, 1)], [], null, [1]);
        $create = new ScopedWrite('create', [], [new ScopedDestination('tt_content', 2)], false, 0);

        self::assertSame([ScopeRefusal::LANGUAGE_NOT_ALLOWED], array_map(static fn (ScopeRefusal $refusal): string => $refusal->reason, $this->checker()->refusalsFor($scope, $create, 0)));
    }

    #[Test]
    public function aDeleteOrMoveOfARecordWithTranslationsInAnotherLanguageIsRefused(): void
    {
        $scope  = new AssignmentScope([new ScopedPage(2, 1)], [], null, [0]);
        $delete = new ScopedWrite('delete', [new RecordReference('tt_content', 21)]);

        // Content 21 has a German translation (31) in the fixture tree.
        self::assertSame([ScopeRefusal::LANGUAGE_NOT_ALLOWED], array_map(static fn (ScopeRefusal $refusal): string => $refusal->reason, $this->checker()->refusalsFor($scope, $delete, 0)));
        self::assertSame([], $this->checker()->refusalsFor(AssignmentScope::forPage(2, 1), $delete, 0), 'with no language list nothing is narrowed');
        self::assertSame([], $this->checker()->refusalsFor($scope, new ScopedWrite('update', [new RecordReference('tt_content', 21)]), 0), 'an update does not take translations along');
    }

    #[Test]
    public function aSubtreeTooLargeToReadIsRefusedUnread(): void
    {
        $checker = new AssignmentScopeChecker(new class extends ArrayRecordTopology {
            public function descendantPages(int $pageUid, int $workspace, int $limit): ?array
            {
                return null;
            }
        });

        self::assertSame(ScopeRefusal::SUBTREE_OUTSIDE_SCOPE, $checker->subtreeRefusal(AssignmentScope::forPage(1, 99), 'delete', 1, 0)?->reason);
        self::assertNull($this->checker()->subtreeRefusal(AssignmentScope::forPage(1, 99), 'delete', 1, 0), 'the same scope reads the real tree and finds it all in scope');
    }

    #[Test]
    public function aClaimIsRefusedForEveryPartThatLiesOutside(): void
    {
        $scope = AssignmentScope::forPage(2, 1);
        $claim = new ScopedWrite(
            'move',
            [new RecordReference('tt_content', 21), new RecordReference('tt_content', 23)],
            [new ScopedDestination('tt_content', 5), new ScopedDestination('tt_content', 2)],
        );

        $refusals = $this->checker()->refusalsFor($scope, $claim, 0);

        self::assertSame(['tt_content:23', 'tt_content (on page 5)'], array_map(static fn (ScopeRefusal $refusal): string => $refusal->subject(), $refusals));
    }

    #[Test]
    public function aClaimOfNothingIsNeverRefused(): void
    {
        self::assertSame([], $this->checker()->refusalsFor(AssignmentScope::none(), ScopedWrite::nothing('clear_cache'), 0));
    }

    #[Test]
    public function theDepthCapOfAConfigurationReachesEveryPageOfTheScope(): void
    {
        $scope  = new AssignmentScope([new ScopedPage(2, 5), new ScopedPage(5, 1)]);
        $capped = $scope->cappedToDepth(0);

        self::assertSame([0, 0], array_map(static fn (ScopedPage $page): int => $page->depth, $capped->pages));
        self::assertNotNull($this->checker()->recordRefusal($capped, 'update', 'tt_content', 22, 0), 'one level below is out under the cap');
        self::assertNull($this->checker()->recordRefusal($scope, 'update', 'tt_content', 22, 0));
        self::assertSame([5, 1], array_map(static fn (ScopedPage $page): int => $page->depth, $scope->pages), "the cap makes a new scope and leaves the run's own as it was");
    }

    private function checker(): AssignmentScopeChecker
    {
        return new AssignmentScopeChecker(new ArrayRecordTopology());
    }
}
