<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

/**
 * The three kinds of typed question a decision answers (ADR-211).
 *
 * @api
 */
enum QuestionType: string
{
    /**
     * Answered with the probability that the answer is yes.
     */
    case YesNo = 'yes_no';

    /**
     * Answered with one option out of a set the profile defines.
     */
    case Choice = 'choice';

    /**
     * Answered with a value on an ordered scale of levels.
     */
    case Score = 'score';
}
