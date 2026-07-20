<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;

/**
 * Central privacy policy governing what per-request content the extension
 * persists, and for how long (ADR-064).
 *
 * A single source of truth read from the extension configuration and applied at
 * every content sink before a write. The default is metadata-only — the
 * behaviour the extension already had by construction.
 */
interface PrivacyPolicyInterface
{
    /**
     * The configured privacy level; PrivacyLevel::METADATA when unset or
     * invalid (safe default: drop content, keep metadata).
     */
    public function level(): PrivacyLevel;

    /**
     * Retention window in days for the per-request log tables, at least 1.
     * A missing, zero or negative setting falls back to 30 days — 0 must not
     * mean "delete everything immediately".
     */
    public function retentionDays(): int;

    /**
     * Retention window in days for one data category, at least 1.
     *
     * Categories exist because the rows differ in sensitivity and in cost of
     * loss: conversation transcripts and agent-run payloads carry prompts,
     * telemetry carries none, and a run awaiting a human decision must outlive
     * the ordinary window. An unset, zero or negative per-category override
     * falls back to {@see retentionDays()}.
     */
    public function retentionDaysFor(PrivacyDataCategory $category): int;

    /**
     * Gate a content payload for persistence according to the current level:
     * NONE/METADATA drop it (null), REDACTED returns a bounded scrubbed copy,
     * FULL returns it unchanged. Null in, null out.
     */
    public function filterContent(?string $content): ?string;
}
