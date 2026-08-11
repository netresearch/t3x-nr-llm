<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

/**
 * One telemetry row that recorded an automatic model selection, read back for
 * the Governance tab (ADR-156).
 *
 * This is the reader :ref:`ADR-142 <adr-142>` made the condition of persisting
 * the trace at all — "a trace whose only reader is a future analytics view is a
 * declaration nothing reads". It answers "why model A" for a call that already
 * happened, where {@see \Netresearch\NrLlm\Domain\ValueObject\RoutingReadout}
 * (ADR-148) answers it for a hypothetical one.
 *
 * Metadata only, exactly like the row it comes from: no prompt, no response, no
 * exception message. The complexity figures ride along because they describe
 * the same request and are read on the same page; they influence nothing.
 */
final readonly class RoutedCall
{
    /**
     * @param list<string> $rejectionReasons distinct RoutingRejectionReason names, sorted
     * @param int          $payloadBytes     total byte length of the message contents on the wire.
     *                                       The only size figure that is always present: it needs
     *                                       no context fit, so it is what the readout can show for
     *                                       a send whose window was never measured.
     * @param ?int         $complexityTokens null where no context fit ran
     * @param ?int         $contextPercent   null where no context fit ran
     */
    public function __construct(
        public string $correlationId,
        public string $operation,
        public string $configurationIdentifier,
        public string $servedModel,
        public bool $success,
        public int $fallbackAttempts,
        public int $latencyMs,
        public string $policyMode,
        public int $candidateCount,
        public array $rejectionReasons,
        public bool $qualitySignalUsed,
        public bool $healthSignalUsed,
        public bool $costSignalUsed,
        public int $complexityScore,
        public int $payloadBytes,
        public ?int $complexityTokens,
        public int $toolCount,
        public ?int $contextPercent,
        public string $shape,
        public int $crdate,
    ) {}

    /**
     * Whether a context fit measured this send, i.e. whether
     * {@see self::$contextPercent} and {@see self::$complexityTokens} hold a
     * measurement at all.
     *
     * The template asks THIS rather than `{call.contextPercent}`, because
     * Fluid's `BooleanNode::convertToBoolean()` casts a numeric through
     * `(bool)(float)`: a measured 0 — every send under roughly half a percent
     * of the budget, which is most short chats — would be indistinguishable
     * from the null case and would render as "not measured", taking the token
     * estimate in the same branch with it.
     */
    public function isContextMeasured(): bool
    {
        return $this->contextPercent !== null;
    }
}
