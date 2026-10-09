<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Inbox;

use Netresearch\NrLlm\Domain\ValueObject\FieldProposal;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;

/**
 * One pending tool call shown to the operator on an approval card (ADR-109),
 * display-only context for the turn-level decision.
 *
 * Deliberately carries NO per-call approval flag: {@see \Netresearch\NrLlm\Service\Agent\ApprovalDecision}
 * is turn-level (one decision covers the whole pending turn), so a per-call
 * verdict would imply a granularity the runtime does not offer. `$argumentsJson`
 * is pre-encoded here (never in the template) and is auto-escaped by Fluid — it
 * is untrusted, model-chosen text.
 *
 * `$previewLines` is what the tool said this call WOULD do, captured when the
 * run suspended (ADR-136) — empty for a tool that declares no preview.
 * `$previewStale` marks a call whose preview no longer matched the record when
 * the run was resumed (ADR-184): the approval was refused without writing, the
 * lines below are the CURRENT ones, and the decision is being asked again.
 *
 * `$previewFailed` marks the lines as the REASON no preview exists rather than
 * the preview itself, so the card can say the decision is being made blind
 * instead of quietly showing nothing.
 *
 * `$pendingTarget` is the record and the fields the call names, as structured
 * values (ADR-214, amending ADR-136) — what a consumer keys an open point to
 * instead of parsing the preview. Null for a call that creates its record, for
 * a tool that does not implement
 * {@see \Netresearch\NrLlm\Service\Tool\PendingTargetInterface}, for a tool
 * that is no longer registered, and for arguments that name no reachable
 * record. `$declaresWrite` says whether the call is a write at all (ADR-214,
 * item 9): a pending call that is not a write is no proposal, and its card
 * offers a plain approve and deny. A tool that is no longer registered counts
 * as a write, as it does for the run's effect fence, and so does a view built
 * without the argument: "not a write" is the answer that has to be stated.
 *
 * `$structuredPreview` is each field the call would change, as structured
 * values (ADR-214, item 9): label, the value stored now, the proposed value
 * and its length against a configured range — what a consumer renders as
 * "current" and "proposed" instead of parsing `$previewLines`, which stay as
 * they are. Read for the viewer when the card is rendered, so it is empty
 * wherever the preview lines are withheld, for a tool that does not implement
 * {@see \Netresearch\NrLlm\Service\Tool\StructuredPreviewInterface}, and for
 * a call the tool would refuse. The values are raw: the consumer escapes them.
 */
final readonly class PendingCallView
{
    /**
     * @param list<string>        $previewLines
     * @param list<FieldProposal> $structuredPreview
     */
    public function __construct(
        public string $name,
        public string $argumentsJson,
        public bool $toolStillRegistered,
        public array $previewLines = [],
        public bool $previewFailed = false,
        public bool $previewStale = false,
        public ?PendingWriteTarget $pendingTarget = null,
        public bool $declaresWrite = true,
        public array $structuredPreview = [],
    ) {}

    /**
     * The structured entries as plain arrays, for a consumer that serialises
     * the card (ADR-214, item 9).
     *
     * @return list<array{field: string, label: string, current: string|null, proposed: string, measure: array{count: int, min: int|null, max: int|null}|null}>
     */
    public function structuredPreviewArray(): array
    {
        return array_map(static fn(FieldProposal $entry): array => $entry->toArray(), $this->structuredPreview);
    }
}
