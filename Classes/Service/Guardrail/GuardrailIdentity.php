<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

/**
 * Stable identity and policy classification shared by both guardrail sides
 * (ADR-106).
 *
 * A per-configuration guardrail policy references a guardrail by a stable
 * identifier — NOT its FQCN — and that identifier is SHARED across the input and
 * output sides: the input and output secret-redaction classes report the same
 * ``secret-redaction`` identifier, so a configuration governs the concept, not a
 * single class.
 *
 * {@see self::isMandatory()} is the per-class AUTHORING signal. The
 * authoritative, security-load-bearing verdict is computed PER IDENTIFIER by
 * {@see GuardrailRegistry}, which fails closed on cross-side disagreement — the
 * {@see GuardrailPolicyResolver} reads the registry's identifier-level verdict,
 * never a raw per-instance flag, so no selection can drop a mandatory guardrail
 * on any axis.
 */
interface GuardrailIdentity
{
    /**
     * A stable kebab-case slug, unique per logical guardrail and shared across
     * the input and output sides (e.g. ``secret-redaction``). It is API: a
     * rename silently drops the guardrail for configurations that stored the old
     * value, so identifiers are immutable (introduce a new guardrail instead).
     */
    public function getIdentifier(): string;

    /**
     * Whether this guardrail is security-critical and always-on (true, e.g.
     * secret redaction) or a selectable policy/advisory guardrail (false). A
     * conscious, PHPStan-checked decision every implementer must declare; a
     * configuration can never switch off a mandatory guardrail.
     */
    public function isMandatory(): bool;
}
