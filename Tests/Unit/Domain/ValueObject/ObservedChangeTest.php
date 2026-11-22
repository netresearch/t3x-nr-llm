<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;
use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChange;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
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

    /**
     * The refusals are cut at the same limit as the changes and counted like
     * them, and a set states whether the rollback of its call could not be
     * confirmed. Both survive the payload and every way a set is rebuilt; a
     * payload without them, written before they existed, reads as nothing cut
     * and nothing unconfirmed (#1448).
     */
    #[Test]
    public function aSetCountsTheRefusalsItCutAndStatesAnUnconfirmedRollback(): void
    {
        $refused = [];
        for ($uid = 1; $uid <= ObservedChangeSet::LIMIT + 10; ++$uid) {
            $refused[] = new ScopeRefusal('update', 'tt_content', $uid, 0, ScopeRefusal::OUTSIDE_SCOPE);
        }

        $set = new ObservedChangeSet([], refused: $refused, refusedOmitted: 2);
        self::assertCount(ObservedChangeSet::LIMIT, $set->refused);
        self::assertSame(12, $set->refusedOmitted);
        self::assertFalse($set->rollbackUnconfirmed);

        $payload = $set->toArray();
        self::assertSame(12, $payload['refusedOmitted'] ?? null);
        self::assertArrayNotHasKey('rollbackUnconfirmed', $payload, 'present only where it says something');
        self::assertEquals($set, ObservedChangeSet::tryFromEventPayload($payload));

        $unconfirmed = ChangeSets::withRollbackUnconfirmed($set);
        self::assertTrue($unconfirmed->rollbackUnconfirmed);
        self::assertSame(12, $unconfirmed->refusedOmitted);
        self::assertTrue($unconfirmed->toArray()['rollbackUnconfirmed'] ?? null);
        self::assertEquals($unconfirmed, ObservedChangeSet::tryFromEventPayload($unconfirmed->toArray()));

        $target = new RecordReference('tt_content', 1);
        foreach ([
            'marked'   => ChangeSets::withTargetMarked($unconfirmed, $target),
            'coverage' => ChangeSets::withCoverage($unconfirmed, ChangeCoverage::NOT_CAPTURED),
            'refused'  => ChangeSets::withRefused($unconfirmed, [new ScopeRefusal('update', 'pages', 1, 0, ScopeRefusal::OUTSIDE_SCOPE)]),
            'merged'   => ChangeSets::merged(new ObservedChangeSet(), $unconfirmed),
        ] as $how => $rebuilt) {
            self::assertTrue($rebuilt->rollbackUnconfirmed, $how . ': the unconfirmed rollback stays');
            self::assertGreaterThanOrEqual(12, $rebuilt->refusedOmitted, $how . ': the count stays');
        }

        self::assertSame(13, ChangeSets::withRefused($unconfirmed, [new ScopeRefusal('update', 'pages', 1, 0, ScopeRefusal::OUTSIDE_SCOPE)])->refusedOmitted, 'one more refusal past the limit is one more cut');
        self::assertSame(15, ChangeSets::merged($set, new ObservedChangeSet(refusedOmitted: 3))->refusedOmitted, 'merged sets add their counts');
        self::assertFalse(ChangeSets::merged($set, new ObservedChangeSet())->rollbackUnconfirmed, 'neither set unconfirmed, the merge is not');
        self::assertTrue(ChangeSets::merged($unconfirmed, new ObservedChangeSet())->rollbackUnconfirmed, 'the first set unconfirmed, the merge is');

        $old = ObservedChangeSet::tryFromEventPayload(['changes' => [], 'changeCount' => 0, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0]);
        self::assertInstanceOf(ObservedChangeSet::class, $old);
        self::assertSame(0, $old->refusedOmitted);
        self::assertFalse($old->rollbackUnconfirmed);
        self::assertSame(['changes' => [], 'changeCount' => 0, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0], $old->toArray(), 'a set with nothing cut or unconfirmed writes the payload it wrote before');
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
    public function aLaterCaptureAddsWhatTheCallsSetDoesNotName(): void
    {
        $page    = new ObservedChange(new RecordReference('pages', 153), ChangeAction::CREATED, 3, 42, isWriteTarget: true);
        $refusal = new ScopeRefusal('update', 'pages', 50, 0, ScopeRefusal::OUTSIDE_SCOPE);
        $set     = new ObservedChangeSet([$page], omitted: 1, refused: [$refusal]);
        $again   = new ObservedChange(new RecordReference('pages', 153), ChangeAction::UPDATED, 3, 42);
        $sameUid = new ObservedChange(new RecordReference('pages', 153), ChangeAction::UPDATED, 4, 42);
        $version = new ObservedChange(new RecordReference('pages', 154), ChangeAction::CREATED, 3, 142);
        $merged  = ChangeSets::merged($set, new ObservedChangeSet([$again, $sameUid, $version], omitted: 2));

        self::assertEquals([$page, $sameUid, $version], $merged->changes, 'a record both name is kept once, as the call states it; another workspace is another row');
        self::assertSame(3, $merged->omitted);
        self::assertSame([$refusal], $merged->refused);
        self::assertSame(ChangeCoverage::DATAHANDLER, $merged->coverage);

        $deleted = ChangeSets::merged($set, new ObservedChangeSet([new ObservedChange(new RecordReference('pages', 153), ChangeAction::DELETED, 3)]))->changes;
        self::assertCount(1, $deleted);
        self::assertSame(ChangeAction::DELETED, $deleted[0]->action, 'the stronger action of the two');
        self::assertSame(42, $deleted[0]->liveUid, "the rest as the call's set states it");
        self::assertTrue($deleted[0]->isWriteTarget);

        self::assertSame(ChangeCoverage::NOT_CAPTURED, ChangeSets::merged($set, new ObservedChangeSet([], ChangeCoverage::NOT_CAPTURED))->coverage, 'a later capture that missed writes says so');
        self::assertSame(ChangeCoverage::NOT_CAPTURED, ChangeSets::merged(new ObservedChangeSet([], ChangeCoverage::NOT_CAPTURED), $set)->coverage);
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

        self::assertSame(RunStep::KIND_TOOL, (new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 0.0, observedChanges: new ObservedChangeSet()))->kind);
    }

    /**
     * A listener write the effect check could not read is not reported as a
     * write outside the scope, and the model may try the call again.
     */
    #[Test]
    public function aListenerWriteThatCouldNotBeCheckedIsReportedAsSuch(): void
    {
        $scope      = AssignmentScope::forPage(2, 1);
        $unreadable = [['refusal' => new ScopeRefusal('effect_written', 'pages', 0, 0, ScopeRefusal::UNRESOLVABLE), 'workspace' => 0]];
        $outside    = [['refusal' => new ScopeRefusal('effect_updated', 'tt_content', 23, 0, ScopeRefusal::OUTSIDE_SCOPE), 'workspace' => 0]];

        $note = ChangeSets::listenerEffectNote($scope, $unreadable, true);
        self::assertStringContainsString('could not be checked', $note);
        self::assertStringContainsString('The call can be tried again.', $note);
        self::assertStringNotContainsString('wrote or tried to write outside it', $note);

        $note = ChangeSets::listenerEffectNote($scope, [...$unreadable, ...$outside], true);
        self::assertStringContainsString('wrote or tried to write outside it', $note, 'one write outside the scope is named as such');
        self::assertStringContainsString('tt_content:23 (outside_scope)', $note);
    }
}
