<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Who decided a recorded approval, relative to who started the run (ADR-173).
 *
 * Both halves of the answer are already stored — the run carries `beUser`, the
 * APPROVAL event's payload carries `decidedBy` ({@see AgentEventKind::APPROVAL})
 * — but nothing put them side by side, so a run its own initiator released read
 * exactly like one a second person reviewed. This enum is the one place the
 * READOUT compares the two uids; the readers ({@see \Netresearch\NrLlm\Service\Agent\Timeline\RunTimelineFactory}
 * for the run timeline, {@see \Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory}
 * for the inbox) render its case and never repeat the comparison.
 *
 * The ENFORCEMENT half compares them separately and differently, in
 * {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::isInitiatorOf()}
 * (ADR-172's four-eyes gate) — which also excludes service accounts, where this
 * enum only has two uids to look at. Neither is a copy of the other, and the two
 * are not interchangeable.
 *
 * This is a READOUT, not a control. Whether self-approval is refused is
 * `require_second_approver` (ADR-172), a per-configuration switch that is
 * default off — which is exactly why the readout matters: where the switch is
 * off, an operator may believe four-eyes applies when it does not.
 *
 * Denials are deliberately out of scope: ADR-172 lets an initiator deny their
 * own run on purpose, so marking a self-denial would flag the one case the
 * design considers correct.
 *
 * Four cases, because two uids give four states and only one of them is a
 * comparison. Both present is SELF or SECOND_PERSON; a missing decider is
 * UNRESOLVED; a missing initiator with a decider present is INITIATOR_UNKNOWN,
 * which is the ordinary shape of a service-account run a human released, not an
 * error. Collapsing the last two into one label would put "the record does not
 * say by whom" on a record that says exactly that.
 */
enum ApprovalAttribution: string
{
    /**
     * The approval was granted by the backend user who started the run.
     *
     * Not an anomaly — for anyone who is not an administrator, not a service
     * account and holds no `AGENT_APPROVE` grant, it is the fallback path in
     * {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::mayActOnRun()};
     * and on a single-operator install every approval is a self-approval,
     * whichever branch of that method admits it. The point is that the record
     * says so.
     */
    case SELF = 'self';

    /**
     * The approval was granted by a backend user other than the initiator —
     * four eyes actually happened.
     */
    case SECOND_PERSON = 'secondPerson';

    /**
     * The record names who approved, but not who started the run: `decidedBy`
     * is a backend user and `beUser` is 0.
     *
     * The normal shape of a run a service account or any non-backend caller
     * began — `beUser` identifies a backend user, so such a run carries 0 and
     * matches nobody ({@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::isInitiatorOf()}).
     * A human then approving it is an ordinary event, not a defect, and it is
     * emphatically not {@see self::UNRESOLVED}: the record does say by whom.
     * What it does not say is against whom to compare.
     *
     * Its own case rather than {@see self::SECOND_PERSON}, because nobody has
     * established that two people were involved — only that the one who decided
     * is not known to be the one who started.
     */
    case INITIATOR_UNKNOWN = 'initiatorUnknown';

    /**
     * The record does not name who approved: `decidedBy` is 0 or negative.
     *
     * Reachable through the readers, which coerce a `decidedBy` that is not an
     * int — a truncated, corrupt or hand-edited payload — to 0 rather than
     * dropping the approval.
     *
     * A sessionless recording is NOT a second way in, though it reads like one:
     * both approve entry points pair
     * {@see \Netresearch\NrLlm\Controller\Backend\BackendUserUidTrait::currentBackendUserUid()}
     * with `currentActor()`, and an actor whose uid is 0 is
     * {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::anonymous()},
     * which {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::mayActOnRun()}
     * refuses before {@see \Netresearch\NrLlm\Service\Agent\ResumeCoordinator::approve()}
     * records anything — pinned by
     * AiActorContextTest::anAnonymousActorMayNotActOnAnyRun(). So the case is
     * defensive against that half, not driven by it.
     *
     * Its own case rather than a silent omission, because "nobody can tell"
     * must not read as "a second person looked" — and 0 === 0 must never
     * produce {@see self::SELF}. The initiator is not consulted here: whether
     * or not the run names one, the fact this row states is that the decider is
     * unrecorded.
     */
    case UNRESOLVED = 'unresolved';

    /**
     * The attribution of ONE recorded approval.
     *
     * Order matters. The decider is asked first, because an unnamed decider is
     * the whole answer whatever the run says; only then can the initiator be
     * absent, which is a different fact and gets a different case.
     */
    public static function fromDecision(int $initiatorBeUser, int $decidedByBeUser): self
    {
        if ($decidedByBeUser <= 0) {
            return self::UNRESOLVED;
        }

        if ($initiatorBeUser <= 0) {
            return self::INITIATOR_UNKNOWN;
        }

        return $initiatorBeUser === $decidedByBeUser ? self::SELF : self::SECOND_PERSON;
    }

    /**
     * The attribution of a whole run, which may have passed several fences.
     * Null when the run recorded no granted approval at all — the caller then
     * shows nothing, because there is nothing to attribute.
     *
     * The strongest case wins, ranked SELF > UNRESOLVED > INITIATOR_UNKNOWN >
     * SECOND_PERSON, so a collapsed row never understates: one self-released
     * fence makes the run self-approved however many others a colleague signed,
     * and a record that names no decider is not rounded up into a claim that a
     * second person looked.
     *
     * That rank has an accepted price. A run with no initiator, one fence
     * decided by user 5 and one whose decider was not recorded, collapses to
     * UNRESOLVED — so the row says the decider is unrecorded while the timeline
     * prints `decidedBy=5` for the other fence. Understating one fence is the
     * direction this readout is allowed to be wrong in; claiming a second pair
     * of eyes is not.
     *
     * Four of the six case pairings occur; two cannot. The initiator is one
     * value for the whole run, so INITIATOR_UNKNOWN never meets SELF or
     * SECOND_PERSON — a run either names an initiator or it does not. Both
     * halves are enumerated by
     * ApprovalAttributionTest::theOnlyPairingsARunCanShowAreTheFourItsInitiatorAllows().
     *
     * @param list<int> $decidedByBeUsers the `decidedBy` uids of the run's GRANTED approvals
     */
    public static function fromDecisions(int $initiatorBeUser, array $decidedByBeUsers): ?self
    {
        $collapsed = null;
        foreach ($decidedByBeUsers as $decidedBy) {
            $attribution = self::fromDecision($initiatorBeUser, $decidedBy);
            if (!$collapsed instanceof self || $attribution->rank() > $collapsed->rank()) {
                $collapsed = $attribution;
            }
        }

        return $collapsed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    /**
     * How loudly this case speaks, for {@see self::fromDecisions()}. Private and
     * unordered by declaration on purpose: the ranking is a display rule, not a
     * property of the case, and tying it to `cases()` order would make adding a
     * case silently reorder the collapse.
     */
    private function rank(): int
    {
        return match ($this) {
            self::SELF              => 4,
            self::UNRESOLVED        => 3,
            self::INITIATOR_UNKNOWN => 2,
            self::SECOND_PERSON     => 1,
        };
    }
}
