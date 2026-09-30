<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

/**
 * A yes/no question, answered with the probability that the answer is yes
 * (ADR-211).
 *
 * @api
 */
final readonly class YesNoQuestion implements DecisionQuestion
{
    /**
     * @param string $yesMeans what a yes stands for, '' when the instructions say it
     * @param string $noMeans  what a no stands for, '' when the instructions say it
     */
    public function __construct(
        public string $key,
        public string $instructions,
        public string $yesMeans = '',
        public string $noMeans = '',
    ) {
        QuestionGuard::key($key);
        QuestionGuard::text($instructions, 'instructions', $key);
        QuestionGuard::optionalText($yesMeans, 'meaning of yes', $key);
        QuestionGuard::optionalText($noMeans, 'meaning of no', $key);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function type(): QuestionType
    {
        return QuestionType::YesNo;
    }

    public function instructions(): string
    {
        return $this->instructions;
    }
}
