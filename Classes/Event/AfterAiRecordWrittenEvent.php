<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Event;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;

/**
 * An AI run wrote a record (ADR-187).
 *
 * Dispatched once per successful editorial write, after the write has landed
 * and been read back by the tool that made it. It says three things and
 * deliberately nothing else: which run, which record, and whether that record
 * was created or changed.
 *
 * **What this event is for.** Everything a consumer might want to do about an
 * AI-written record and this extension will not do for them: an Article 50
 * transparency label, an entry in someone else's audit trail, a badge in the
 * page module, a report, a hand-off to an external compliance system. None of
 * those ship here, and no listener ships here either — the extension's job ends
 * at telling the truth about the write.
 *
 * **What it deliberately does not carry.** No field values, no before/after, no
 * rendered content, not even the name of the tool. A payload is a copy, and a
 * copy of editorial content in an event is a second place for it to leak from
 * and a second place for it to go stale. {@see $record} names the row; a
 * listener that needs its content reads the row, under its own permissions, at
 * the moment it actually needs it.
 *
 * **Ordering.** Dispatched from the tool loop's single execution choke point,
 * BEFORE the run trace persists its step for the same call. A listener must not
 * try to join `tx_nrllm_agentrun`'s trace for this write — it is not there yet.
 * Everything the listener needs is on this event, which is why it can be
 * dispatched at the earliest honest moment rather than the most convenient one.
 *
 * **Delivery.** Once per successful write, not once per record: the at-least-once
 * queue may re-execute a reaped run (ADR-104), and an idempotent write that runs
 * twice dispatches twice with the same {@see $correlationId} and
 * {@see $record}. A listener that must act once per record deduplicates on that
 * pair. A non-idempotent write is never auto-retried, so it cannot double this
 * way.
 *
 * @api
 */
final readonly class AfterAiRecordWrittenEvent
{
    /**
     * @param string          $correlationId the agent run's uuid, which is the correlation id
     *                                       of everything that run did (ADR-153). Never empty:
     *                                       a tool call made outside a persisted run has no
     *                                       provenance to report and dispatches no event.
     * @param RecordReference $record        the row the write produced or changed
     * @param WriteKind       $kind          whether that row was created or updated
     *
     * @throws InvalidArgumentException if the correlation id is empty
     */
    public function __construct(
        public string $correlationId,
        public RecordReference $record,
        public WriteKind $kind,
    ) {
        if (trim($correlationId) === '') {
            throw new InvalidArgumentException(sprintf(
                'An AI-write provenance event needs the run it belongs to; got an empty correlation id for %s.',
                $record,
            ), 1788500001);
        }
    }
}
