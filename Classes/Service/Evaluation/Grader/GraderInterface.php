<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation\Grader;

use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use RuntimeException;

/**
 * A grading strategy that scores a model response against a golden prompt
 * (ADR-060).
 *
 * Implementations are selected by identifier through GradingService. The
 * deterministic grader is the default; the decision grader is opt-in
 * because it spends tokens on a decision call (ADR-211).
 */
interface GraderInterface
{
    /**
     * Stable identifier used to select this grader (e.g. `deterministic`).
     */
    public function getIdentifier(): string;

    /**
     * Grade the given response against the prompt's expectations.
     */
    public function grade(string $response, GoldenPrompt $prompt): GradingResult;

    /**
     * Refuse before a run spends anything when this grader cannot grade at
     * all — a missing configuration, say. A run otherwise pays for every
     * completion and only then learns that none can be graded.
     *
     * @throws RuntimeException naming why
     */
    public function assertReady(): void;
}
