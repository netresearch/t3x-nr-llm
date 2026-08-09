<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Inbox;

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
 * `$previewFailed` marks the lines as the REASON no preview exists rather than
 * the preview itself, so the card can say the decision is being made blind
 * instead of quietly showing nothing.
 */
final readonly class PendingCallView
{
    /**
     * @param list<string> $previewLines
     */
    public function __construct(
        public string $name,
        public string $argumentsJson,
        public bool $toolStillRegistered,
        public array $previewLines = [],
        public bool $previewFailed = false,
    ) {}
}
