<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why a model was not eligible for a routing decision (ADR-142).
 *
 * A rejection is a HARD constraint: no ranking signal can bring a rejected
 * candidate back. That separation is the point of naming the reasons at all —
 * an operator asking "why not this model" gets an answer that is a fact about
 * the model, not a position in a score table.
 *
 * Reported in evaluation order, cheapest first, mirroring
 * {@see ToolDenialReason}.
 *
 * @internal
 */
enum RoutingRejectionReason: string
{
    /**
     * The operator's declared capability requirements are not all met.
     */
    case CAPABILITY_MISSING = 'capabilityMissing';

    /**
     * The model declares capabilities, and the one this operation needs is not
     * among them (ADR-138). A model declaring NOTHING is not rejected here:
     * an empty capability set means "undeclared", not "cannot".
     */
    case OPERATION_CAPABILITY_MISSING = 'operationCapabilityMissing';

    /**
     * The model's provider uses an adapter type the criteria exclude.
     */
    case ADAPTER_NOT_ALLOWED = 'adapterNotAllowed';

    /**
     * The model's context window is smaller than the criteria require. A model
     * with an UNKNOWN (0) context length is rejected too: a minimum was asked
     * for and cannot be shown to be met.
     */
    case CONTEXT_TOO_SMALL = 'contextTooSmall';

    /**
     * The model's input cost exceeds the ceiling the criteria set.
     */
    case COST_ABOVE_LIMIT = 'costAboveLimit';

    /**
     * The label an operator reads instead of the wire value (ADR-148).
     *
     * Named `get…` because Fluid reaches a method only through the
     * get/is/has convention: `{reason.labelKey}` resolves `getLabelKey()`,
     * and a plain `labelKey()` silently yields null — which reaches
     * `f:translate` as an empty key and throws at render time. Same shape as
     * {@see \Netresearch\NrLlm\Service\Governance\GovernanceProfile::getLabelKey()}.
     */
    public function getLabelKey(): string
    {
        return 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:routing.rejection.' . $this->name;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
