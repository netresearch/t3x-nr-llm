<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Change;

use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChange;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;

/**
 * What the tool loop does with an {@see ObservedChangeSet}: judge it against
 * the tool's own statement, mark the target and word it for the model
 * (ADR-292). Kept out of the value objects so that their @api surface is the
 * shape a consumer reads and nothing the loop needs.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ChangeSets
{
    /** Identities the note a failed call gets names at most. */
    private const NOTE_LIMIT = 10;

    public static function isEmpty(ObservedChangeSet $set): bool
    {
        return $set->changes === [] && $set->omitted === 0;
    }

    /**
     * Whether the set holds the record a tool stated as its write: by its uid,
     * or by the live uid of the version that was written.
     */
    public static function holdsTarget(ObservedChangeSet $set, RecordReference $target): bool
    {
        foreach ($set->changes as $change) {
            if (self::isTarget($change, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The set with the change that is the tool's own write target marked.
     */
    public static function withTargetMarked(ObservedChangeSet $set, ?RecordReference $target): ObservedChangeSet
    {
        if (!$target instanceof RecordReference) {
            return $set;
        }

        $marked = array_map(
            static fn (ObservedChange $change): ObservedChange => new ObservedChange(
                $change->record,
                $change->action,
                $change->workspace,
                $change->liveUid,
                $change->language,
                self::isTarget($change, $target),
                $change->hidden,
                $change->restrictions,
            ),
            $set->changes,
        );

        return new ObservedChangeSet($marked, $set->coverage, $set->omitted);
    }

    public static function withCoverage(ObservedChangeSet $set, ChangeCoverage $coverage): ObservedChangeSet
    {
        return new ObservedChangeSet($set->changes, $coverage, $set->omitted);
    }

    /**
     * One bounded line for the model after a call that failed: what the call
     * changed before it did. Identities only; empty for an empty list.
     */
    public static function failureNote(ObservedChangeSet $set): string
    {
        if ($set->changes === []) {
            return '';
        }

        $parts = array_map(
            static fn (ObservedChange $change): string => sprintf('%s (%s)', $change->record, $change->action->value),
            array_slice($set->changes, 0, self::NOTE_LIMIT),
        );
        $more = $set->omitted + max(0, count($set->changes) - self::NOTE_LIMIT);

        return sprintf(
            'Before it failed this call changed: %s%s. Check these records before retrying.',
            implode(', ', $parts),
            $more > 0 ? sprintf(' and %d more', $more) : '',
        );
    }

    private static function isTarget(ObservedChange $change, RecordReference $target): bool
    {
        return $change->record->table === $target->table && ($change->record->uid === $target->uid || $change->liveUid === $target->uid);
    }
}
