<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChange;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\Change\ChangeSets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservedChange::class)]
#[CoversClass(ObservedChangeSet::class)]
#[CoversClass(RunStep::class)]
#[CoversClass(ChangeSets::class)]
final class ObservedChangeTest extends TestCase
{
    #[Test]
    public function aChangeRoundTripsThroughItsPayload(): void
    {
        $change = new ObservedChange(new RecordReference('tt_content', 12), ChangeAction::CREATED, 3, 7, 1, true, true, ['starttime', 'fe_group']);

        $payload = $change->toArray();

        self::assertSame(
            ['table' => 'tt_content', 'uid' => 12, 'action' => 'created', 'workspace' => 3, 'draft' => true, 'liveUid' => 7, 'language' => 1, 'writeTarget' => true, 'hidden' => true, 'restrictions' => ['starttime', 'fe_group']],
            $payload,
        );
        self::assertEquals($change, ObservedChange::tryFromArray($payload));
    }

    /**
     * Hidden, draft and restricted are three facts. A row that is not hidden
     * can still be a draft, and nothing here says it is public.
     */
    #[Test]
    public function hiddenDraftAndRestrictionsAreSeparateFacts(): void
    {
        $visibleDraft = new ObservedChange(new RecordReference('pages', 5), ChangeAction::UPDATED, 2, 4, null, false, false, ['endtime']);

        self::assertFalse($visibleDraft->hidden);
        self::assertTrue($visibleDraft->isDraft());
        self::assertSame(['endtime'], $visibleDraft->restrictions);

        $live = new ObservedChange(new RecordReference('pages', 5), ChangeAction::UPDATED);
        self::assertFalse($live->isDraft());
        self::assertNull($live->hidden, 'a table without a disable flag states none');
        self::assertArrayNotHasKey('hidden', $live->toArray());
    }

    #[Test]
    public function aRestrictionOutsideTheKnownSetIsDropped(): void
    {
        $change = new ObservedChange(new RecordReference('pages', 5), ChangeAction::UPDATED, restrictions: ['fe_group', 'whatever', 'starttime']);

        self::assertSame(['starttime', 'fe_group'], $change->restrictions);
    }

    #[Test]
    public function aPayloadThatIsNotAChangeIsSkippedAndNotThrown(): void
    {
        self::assertNull(ObservedChange::tryFromArray(['table' => 'pages', 'uid' => 5, 'action' => 'vaporised']));
        self::assertNull(ObservedChange::tryFromArray(['table' => 'pages;drop', 'uid' => 5, 'action' => 'created']));
        self::assertNull(ObservedChange::tryFromArray(['table' => 'pages', 'uid' => '5', 'action' => 'created']));
        self::assertNull(ObservedChange::tryFromArray([]));
    }

    #[Test]
    public function aSetKeepsAtMostTheLimitAndCountsTheRest(): void
    {
        $changes = [];
        for ($uid = 1; $uid <= ObservedChangeSet::LIMIT + 3; ++$uid) {
            $changes[] = new ObservedChange(new RecordReference('tt_content', $uid), ChangeAction::CREATED);
        }

        $set = new ObservedChangeSet($changes, omitted: 2);

        self::assertCount(ObservedChangeSet::LIMIT, $set->changes);
        self::assertSame(5, $set->omitted);
        self::assertSame(5, $set->toArray()['changesOmitted']);
        self::assertSame(ObservedChangeSet::LIMIT, $set->toArray()['changeCount']);
    }

    #[Test]
    public function aSetNeverStatesCompleteness(): void
    {
        self::assertSame(['datahandler', 'not_captured'], array_map(static fn (ChangeCoverage $coverage): string => $coverage->value, ChangeCoverage::cases()));
        self::assertSame('datahandler', (new ObservedChangeSet())->toArray()['changeCoverage']);
    }

    #[Test]
    public function theEventPayloadReadsBackWithoutTheCallerParsingArrays(): void
    {
        $set = new ObservedChangeSet(
            [new ObservedChange(new RecordReference('pages', 9), ChangeAction::MOVED, 0, null, null, true, false)],
            ChangeCoverage::NOT_CAPTURED,
            4,
        );
        $step = new RunStep(kind: RunStep::KIND_CHANGES, round: 1, durationMs: 0.0, toolName: 'x', observedChanges: $set);

        $read = ObservedChangeSet::tryFromEventPayload($step->toArray());

        self::assertEquals($set, $read);
        self::assertNull(ObservedChangeSet::tryFromEventPayload(['kind' => 'tool']), 'a payload of another kind holds no list');
    }

    #[Test]
    public function anUnknownCoverageOrAnUnreadableEntryDegradesInsteadOfThrowing(): void
    {
        $read = ObservedChangeSet::tryFromEventPayload([
            'changes'        => [['table' => 'pages', 'uid' => 1, 'action' => 'created'], 'junk', ['table' => 'pages', 'uid' => 2, 'action' => 'nonsense']],
            'changeCoverage' => 'complete',
        ]);

        self::assertInstanceOf(ObservedChangeSet::class, $read);
        self::assertCount(1, $read->changes);
        self::assertSame(ChangeCoverage::NOT_CAPTURED, $read->coverage, 'a coverage this version does not know is never read as a promise');
    }

    #[Test]
    public function theTargetIsFoundByItsUidOrByTheLiveUidOfItsVersion(): void
    {
        $version = new ObservedChange(new RecordReference('tt_content', 90), ChangeAction::UPDATED, 1, 10);
        $other   = new ObservedChange(new RecordReference('pages', 10), ChangeAction::UPDATED);
        $set     = new ObservedChangeSet([$version, $other]);

        self::assertTrue(ChangeSets::holdsTarget($set, new RecordReference('tt_content', 10)));
        self::assertTrue(ChangeSets::holdsTarget($set, new RecordReference('tt_content', 90)));
        self::assertFalse(ChangeSets::holdsTarget($set, new RecordReference('tt_content', 11)));

        $marked = ChangeSets::withTargetMarked($set, new RecordReference('tt_content', 10));
        self::assertTrue($marked->changes[0]->isWriteTarget);
        self::assertFalse($marked->changes[1]->isWriteTarget, 'the same uid in another table is not the target');
    }

    #[Test]
    public function theFailureNoteNamesIdentitiesOnlyAndIsBounded(): void
    {
        self::assertSame('', ChangeSets::failureNote(new ObservedChangeSet()));

        $changes = [];
        for ($uid = 1; $uid <= 14; ++$uid) {
            $changes[] = new ObservedChange(new RecordReference('tt_content', $uid), $uid === 1 ? ChangeAction::CREATED : ChangeAction::UPDATED);
        }

        $note = ChangeSets::failureNote(new ObservedChangeSet($changes, omitted: 1));

        self::assertStringStartsWith('Before it failed this call changed: tt_content:1 (created), tt_content:2 (updated)', $note);
        self::assertStringContainsString('tt_content:10 (updated) and 5 more.', $note);
        self::assertStringNotContainsString('tt_content:11', $note);
    }

    #[Test]
    public function onlyAChangesStepCarriesChanges(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 0.0, observedChanges: new ObservedChangeSet());
    }
}
