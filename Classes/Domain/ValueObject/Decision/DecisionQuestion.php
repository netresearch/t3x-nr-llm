<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

/**
 * One typed question of a decision profile (ADR-211).
 *
 * Implemented by {@see YesNoQuestion}, {@see ChoiceQuestion} and
 * {@see ScoreQuestion} only; a provider translates exactly these three.
 *
 * @api
 */
interface DecisionQuestion
{
    /**
     * The identifier the answer comes back under. Unique within a profile.
     */
    public function key(): string;

    public function type(): QuestionType;

    /**
     * What the model is asked to decide, in the profile author's words. It
     * may refer to the subject fields by name (`task`, `candidate`,
     * `evidence`).
     */
    public function instructions(): string;
}
