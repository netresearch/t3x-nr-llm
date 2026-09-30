<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision\Profile;

use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\SubjectField;

/**
 * The decision profiles nr-llm itself uses (ADR-211).
 *
 * `nr_llm.task_fulfilment` is what the decision grader of the evaluation asks
 * (ADR-060): how well a response fulfils its task, with an optional reference
 * answer as evidence. Its five levels are the rubric a golden-set run is
 * compared on; changing a level changes the yardstick, so raise the version.
 */
final readonly class BuiltinDecisionProfileProvider implements DecisionProfileProviderInterface
{
    public const TASK_FULFILMENT = 'nr_llm.task_fulfilment';

    public const TASK_FULFILMENT_QUESTION = 'fulfilment';

    /** Lowest first; the grader scales the answer by the top index. */
    public const TASK_FULFILMENT_LEVELS = [
        'Fails the task entirely, or answers something else',
        'Addresses the task, but is mostly wrong or incomplete',
        'Fulfils part of the task; important parts are missing or wrong',
        'Fulfils the task with minor gaps or inaccuracies',
        'Fulfils the task completely and correctly',
    ];

    public function getDecisionProfiles(): array
    {
        return [
            new DecisionProfile(
                identifier: self::TASK_FULFILMENT,
                version: 1,
                questions: [
                    new ScoreQuestion(
                        self::TASK_FULFILMENT_QUESTION,
                        'How well does `candidate` fulfil `task`? Where `evidence` holds a reference answer, '
                        . 'judge the substance of `candidate` against it, not its wording.',
                        self::TASK_FULFILMENT_LEVELS,
                    ),
                ],
                requires: [SubjectField::Task, SubjectField::Candidate],
                description: 'How well a response fulfils its task (evaluation grader).',
            ),
        ];
    }
}
