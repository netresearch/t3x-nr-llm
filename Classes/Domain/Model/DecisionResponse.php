<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;

/**
 * What a decision-capable provider answered (ADR-211).
 *
 * The provider-level counterpart of a completion response: the answers keyed
 * by question key, the model that answered as the provider reported it, and
 * the usage the pipeline records. Like every usage in this extension, three
 * zero counts mean the provider reported none.
 *
 * @api
 */
final readonly class DecisionResponse
{
    /**
     * @param array<string, DecisionAnswer> $answers
     */
    public function __construct(
        public array $answers,
        public string $model,
        public UsageStatistics $usage,
        public ProbabilityKind $probabilityKind,
        public string $provider = '',
    ) {}

    /**
     * The same response with a usage the manager priced — the cost of the
     * model that actually served, which only the pipeline terminal knows.
     */
    public function withUsage(UsageStatistics $usage): self
    {
        return new self($this->answers, $this->model, $usage, $this->probabilityKind, $this->provider);
    }
}
