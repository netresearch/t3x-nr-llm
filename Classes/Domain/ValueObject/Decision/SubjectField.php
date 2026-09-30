<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

/**
 * The parts of a {@see DecisionSubject} a profile can require (ADR-211). The
 * value is the name the questions refer to and the key the model receives.
 *
 * @api
 */
enum SubjectField: string
{
    /**
     * What was asked for: the question, the briefing, the task.
     */
    case Task = 'task';

    /**
     * What is being judged: an answer, a draft, a passage, a planned action.
     */
    case Candidate = 'candidate';

    /**
     * The material the candidate is judged against: sources, reference texts.
     */
    case Evidence = 'evidence';
}
