<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;

/**
 * The answers to every question of a profile, with where they came from
 * (ADR-211).
 *
 * It holds information, not a verdict: what follows from an answer is the
 * caller's to decide. `$probabilityKind` says what the probabilities and
 * confidences are — a vendor's calibrated distribution, a model's own
 * uncalibrated one, or none at all because the model gave hard labels.
 * Token counts and cost are null when nothing reported them, never 0 in their
 * place; the cost of a chat model's structured answer is in its usage record,
 * not here.
 *
 * @api
 */
final readonly class DecisionResult
{
    /**
     * @param array<string, DecisionAnswer> $answers       keyed by question key, one per question of the profile
     * @param string                        $configuration the configuration that was asked
     * @param string                        $provider      the provider adapter that answered
     * @param string                        $model         the model that answered, as the provider reported it
     */
    public function __construct(
        public string $profile,
        public int $profileVersion,
        public string $configuration,
        public string $provider,
        public string $model,
        public ProbabilityKind $probabilityKind,
        public array $answers,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?float $cost = null,
    ) {}

    /**
     * @throws DecisionException when the profile has no question under that key
     */
    public function answer(string $key): DecisionAnswer
    {
        return $this->answers[$key] ?? throw DecisionException::noSuchAnswer($this->profile, $key);
    }
}
