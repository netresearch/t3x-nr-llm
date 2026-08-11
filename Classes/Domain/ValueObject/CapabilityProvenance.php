<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;

/**
 * One capability of one model, with where the declaration came from and when
 * it was last confirmed (ADR-160).
 *
 * Built by {@see \Netresearch\NrLlm\Domain\Model\Model::getCapabilityProvenance()}
 * by comparing the model's declared capabilities against the set the last
 * provider discovery reported. A capability the provider named is attributed
 * to that run; one only the operator ticked is attributed to the operator and
 * carries no confirmation date, because there is none to carry.
 *
 * @api
 */
final readonly class CapabilityProvenance
{
    public function __construct(
        public ModelCapability $capability,
        public CapabilitySource $source,
        public ?DateTimeImmutable $confirmedAt = null,
    ) {}

    /**
     * The capability token, for templates and JSON payloads that want the
     * string rather than the enum.
     */
    public function getName(): string
    {
        return $this->capability->value;
    }

    public function getSourceValue(): string
    {
        return $this->source->value;
    }

    /**
     * True only when a live provider answer confirmed this capability. The
     * bundled catalog and an operator's tick are both assumptions, and the
     * operator surfaces have to be able to say so.
     */
    public function isVerified(): bool
    {
        return $this->source->isVerification() && $this->confirmedAt instanceof DateTimeImmutable;
    }
}
