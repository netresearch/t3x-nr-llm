<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Fallback;

/**
 * Why {@see FallbackCandidateResolver} dropped a chain entry (ADR-137).
 *
 * The reason is reported to the caller instead of being logged in the
 * resolver: the two candidate loops word their skip lines differently — the
 * middleware distinguishes the two cases, the streaming dispatcher collapses
 * them — and the merge must not rewrite either log surface.
 *
 * @internal
 */
enum FallbackSkipReason
{
    /**
     * No configuration row carries this identifier.
     */
    case NotFound;

    /**
     * The configuration exists but is switched off.
     */
    case Inactive;
}
