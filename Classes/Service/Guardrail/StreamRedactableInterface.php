<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

/**
 * Marker for a {@see GuardrailInterface} whose REDACT verdict should also be
 * applied to the LIVE stream (ADR-088).
 *
 * Only a guardrail that actually redacts (returns REDACT) benefits from the
 * streaming holdback buffer in
 * {@see \Netresearch\NrLlm\Service\Streaming\StreamingDispatcher}: a policy-only
 * guardrail (DENY / REQUIRE_APPROVAL, e.g. the provider content filter) cannot
 * retract a sent stream, so it must NOT pull the dispatcher onto the buffered,
 * holdback path — that would cost memory and latency for no masking. Such a
 * guardrail is still run by the end-of-stream audit; it simply does not opt into
 * live redaction by implementing this marker.
 */
interface StreamRedactableInterface {}
