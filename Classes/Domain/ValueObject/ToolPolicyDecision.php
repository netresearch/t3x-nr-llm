<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;

/**
 * The outcome of evaluating the tool gate for one tool (ADR-094).
 *
 * Carries the reason as data rather than as a filtered-out absence, so the
 * playground and the run trace can tell an administrator why a tool they ticked
 * did not appear. Without it, a tool silently missing from a run looks like a
 * bug in the checkbox.
 */
final readonly class ToolPolicyDecision
{
    public function __construct(
        public string $toolName,
        public bool $allowed,
        public ToolDataClass $dataClass,
        public TrustZone $zone,
        public ToolDataClass $ceiling,
        public ToolDenialReason $reason = ToolDenialReason::NONE,
        /** True when the tool was denied by the zone gate but let through because enforcement is in observe mode. */
        public bool $observedOnly = false,
    ) {}

    /**
     * A short, admin-readable explanation. Never contains tool arguments or
     * output — only policy facts.
     */
    public function message(): string
    {
        return match ($this->reason) {
            ToolDenialReason::NONE => sprintf('%s is available.', $this->toolName),
            ToolDenialReason::NOT_REGISTERED => sprintf('%s is not a registered tool.', $this->toolName),
            ToolDenialReason::TOOL_DISABLED => sprintf('%s is disabled globally or its group is switched off.', $this->toolName),
            ToolDenialReason::REQUIRES_ADMIN => sprintf('%s is available to administrators only.', $this->toolName),
            ToolDenialReason::CONFIGURATION_GROUP => sprintf(
                '%s is outside the tool groups this configuration allows.',
                $this->toolName,
            ),
            ToolDenialReason::TRUST_ZONE => sprintf(
                '%s returns %s data, which exceeds what a provider in the "%s" trust zone may receive (ceiling: %s).%s',
                $this->toolName,
                $this->dataClass->value,
                $this->zone->value,
                $this->ceiling->value,
                $this->observedOnly ? ' Enforcement is in observe mode, so the tool was still offered.' : '',
            ),
        };
    }
}
