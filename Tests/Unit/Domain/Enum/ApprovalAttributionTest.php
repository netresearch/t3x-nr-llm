<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ApprovalAttribution;
use Netresearch\NrLlm\Tests\Unit\Language\LabelCatalogue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one comparison of ADR-173. Everything that displays a self-approval reads
 * its answer, so the guards live here rather than in each reader.
 */
#[CoversNothing]
final class ApprovalAttributionTest extends TestCase
{
    #[Test]
    public function theInitiatorDecidingTheirOwnRunIsSelfApproval(): void
    {
        self::assertSame(ApprovalAttribution::SELF, ApprovalAttribution::fromDecision(4, 4));
    }

    #[Test]
    public function anotherBackendUserDecidingIsASecondPerson(): void
    {
        self::assertSame(ApprovalAttribution::SECOND_PERSON, ApprovalAttribution::fromDecision(4, 9));
    }

    /**
     * The absent-user trap: `beUser` is 0 for a run a service account started and
     * `decidedBy` is 0 for a decision whose actor could not be resolved. A plain
     * `===` would answer SELF to 0 === 0 and put a marker on a run nobody
     * self-approved.
     */
    #[Test]
    public function anAbsentUserOnEitherSideIsNeverSelf(): void
    {
        self::assertNotSame(ApprovalAttribution::SELF, ApprovalAttribution::fromDecision(0, 0));
        self::assertNotSame(ApprovalAttribution::SELF, ApprovalAttribution::fromDecision(0, 4));
        self::assertNotSame(ApprovalAttribution::SELF, ApprovalAttribution::fromDecision(4, 0));
        self::assertNotSame(ApprovalAttribution::SELF, ApprovalAttribution::fromDecision(-1, -1));
    }

    /**
     * The two absences are DIFFERENT facts and must not share a label. A run a
     * service account started carries `beUser = 0`; a backend user approving it
     * is recorded by uid, so "the record does not say by whom" would be false —
     * what it does not say is who started the run.
     */
    #[Test]
    public function aKnownDeciderOnARunWithNoInitiatorIsItsOwnCase(): void
    {
        self::assertSame(ApprovalAttribution::INITIATOR_UNKNOWN, ApprovalAttribution::fromDecision(0, 5));
        self::assertSame(ApprovalAttribution::INITIATOR_UNKNOWN, ApprovalAttribution::fromDecision(-1, 5));
    }

    /**
     * The mirror: the decider decides the answer first, so an unrecorded decider
     * reads the same whether or not the run names an initiator.
     */
    #[Test]
    public function anUnrecordedDeciderIsUnresolvedWhateverTheRunSays(): void
    {
        self::assertSame(ApprovalAttribution::UNRESOLVED, ApprovalAttribution::fromDecision(4, 0));
        self::assertSame(ApprovalAttribution::UNRESOLVED, ApprovalAttribution::fromDecision(0, 0));
        self::assertSame(ApprovalAttribution::UNRESOLVED, ApprovalAttribution::fromDecision(4, -1));
    }

    #[Test]
    public function aRunWithNoRecordedApprovalHasNoAttribution(): void
    {
        self::assertNull(ApprovalAttribution::fromDecisions(4, []));
    }

    /**
     * A run can pass several fences. One self-released fence is the fact the row
     * has to state, however many others a colleague signed.
     */
    #[Test]
    public function oneSelfApprovalAmongSeveralDecidesTheWholeRun(): void
    {
        self::assertSame(ApprovalAttribution::SELF, ApprovalAttribution::fromDecisions(4, [9, 4]));
        self::assertSame(ApprovalAttribution::SELF, ApprovalAttribution::fromDecisions(4, [4, 9]));
        self::assertSame(ApprovalAttribution::SELF, ApprovalAttribution::fromDecisions(4, [0, 4]));
    }

    #[Test]
    public function anUnresolvedDecisionIsNotRoundedUpIntoASecondPerson(): void
    {
        self::assertSame(ApprovalAttribution::UNRESOLVED, ApprovalAttribution::fromDecisions(4, [9, 0]));
        self::assertSame(ApprovalAttribution::UNRESOLVED, ApprovalAttribution::fromDecisions(4, [0, 9]));
    }

    #[Test]
    public function severalDecisionsByOthersStayASecondPerson(): void
    {
        self::assertSame(ApprovalAttribution::SECOND_PERSON, ApprovalAttribution::fromDecisions(4, [9, 11]));
    }

    /**
     * A run with no initiator can only mix INITIATOR_UNKNOWN with UNRESOLVED —
     * SELF and SECOND_PERSON are unreachable for it. The unrecorded decider
     * still wins, so the collapsed row never claims a decider it does not have.
     */
    #[Test]
    public function aRunWithoutAnInitiatorCollapsesToUnresolvedOnlyWhenADeciderIsMissing(): void
    {
        self::assertSame(ApprovalAttribution::INITIATOR_UNKNOWN, ApprovalAttribution::fromDecisions(0, [5]));
        self::assertSame(ApprovalAttribution::INITIATOR_UNKNOWN, ApprovalAttribution::fromDecisions(0, [5, 9]));
        self::assertSame(ApprovalAttribution::UNRESOLVED, ApprovalAttribution::fromDecisions(0, [5, 0]));
        self::assertSame(ApprovalAttribution::UNRESOLVED, ApprovalAttribution::fromDecisions(0, [0, 5]));
    }

    /**
     * {@see ApprovalAttribution::fromDecisions()} claims four of the six case
     * pairings occur and two cannot. Enumerated rather than argued: every
     * initiator × two-decider combination the uid domain allows, reduced to the
     * pairs one run can show. Both halves are asserted, because the count in the
     * docblock was written backwards once already.
     */
    #[Test]
    public function theOnlyPairingsARunCanShowAreTheFourItsInitiatorAllows(): void
    {
        $uids = [-1, 0, 4, 9];
        $seen = [];

        foreach ($uids as $initiator) {
            foreach ($uids as $firstDecider) {
                foreach ($uids as $secondDecider) {
                    $first  = ApprovalAttribution::fromDecision($initiator, $firstDecider);
                    $second = ApprovalAttribution::fromDecision($initiator, $secondDecider);
                    if ($first === $second) {
                        continue;
                    }

                    $pair = [$first->name, $second->name];
                    sort($pair);
                    $seen[implode('+', $pair)] = true;
                }
            }
        }

        $pairs = array_keys($seen);
        sort($pairs);

        self::assertSame([
            'INITIATOR_UNKNOWN+UNRESOLVED',
            'SECOND_PERSON+SELF',
            'SECOND_PERSON+UNRESOLVED',
            'SELF+UNRESOLVED',
        ], $pairs);

        // The two the initiator being one value for the whole run rules out.
        self::assertNotContains('INITIATOR_UNKNOWN+SELF', $pairs);
        self::assertNotContains('INITIATOR_UNKNOWN+SECOND_PERSON', $pairs);
    }

    #[Test]
    public function theCaseValuesAreTheTranslationKeySuffixesThePartialSwitchesOn(): void
    {
        self::assertSame(['self', 'secondPerson', 'initiatorUnknown', 'unresolved'], ApprovalAttribution::values());
    }

    /**
     * Both surfaces delegate to ONE partial, which carries a branch per case and
     * an English `default` on each. The alternative — a key assembled from the
     * case value — has no `default` at all: renaming a case, or adding one,
     * would empty the cell on both surfaces with every other test still green.
     */
    #[Test]
    public function everyCaseIsRenderedByTheSharedPartial(): void
    {
        $partial = self::read('Resources/Private/Partials/Backend/AgentRun/ApprovalAttribution.html');

        foreach (ApprovalAttribution::values() as $value) {
            $key     = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:runs.attribution.' . $value;
            $english = LabelCatalogue::source($key);
            self::assertNotNull($english, 'No English text for ' . $key);

            self::assertStringContainsString('<f:case value="' . $value . '">', $partial, 'No branch for ' . $value);
            self::assertStringContainsString('key="' . $key . '"', $partial, 'No translation key for ' . $value);
            self::assertStringContainsString('default="' . $english . '"', $partial, 'No English fallback for ' . $value);
        }
    }

    #[Test]
    public function neitherSurfaceAssemblesTheTranslationKeyItself(): void
    {
        $views = [
            'Resources/Private/Partials/Backend/AgentRun/TerminalTable.html',
            'Resources/Private/Templates/Backend/AgentRun/Show.html',
        ];

        foreach ($views as $view) {
            $markup = self::read($view);

            self::assertStringContainsString('partial="Backend/AgentRun/ApprovalAttribution"', $markup, $view);
            self::assertStringNotContainsString('runs.attribution.{', $markup, $view);
        }
    }

    /**
     * Every case has to be renderable. The shared partial resolves
     * `runs.attribution.<value>` per branch, and a case whose catalogue entry is
     * missing falls back to the branch's English `default` — in German it then
     * silently shows English, which is the failure a reviewer cannot see in the
     * diff that adds the case.
     */
    #[Test]
    public function everyCaseHasAnEnglishAndAGermanLabel(): void
    {
        foreach (ApprovalAttribution::values() as $value) {
            $key = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:runs.attribution.' . $value;

            self::assertNotNull(LabelCatalogue::source($key), 'No English text for ' . $key);
            self::assertNotNull(LabelCatalogue::target($key), 'No German text for ' . $key);
        }
    }

    private static function read(string $relativePath): string
    {
        $path     = __DIR__ . '/../../../../' . $relativePath;
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'Unreadable: ' . $path);

        return $contents;
    }
}
