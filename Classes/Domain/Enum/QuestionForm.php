<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The question forms a golden retrieval question can declare (ADR-072).
 *
 * The form classifies how the question relates to the vocabulary of its
 * target documents and is the primary split for hit-rate reporting:
 *
 * - MATCH the question's vocabulary overlaps the target document, so
 *         lexical and vector retrieval both have a fair chance
 * - GAP   an everyday rewording with little vocabulary overlap — the
 *         class retrieval quality problems live in, and the one a
 *         retrieval change is usually meant to improve
 */
enum QuestionForm: string
{
    case MATCH = 'match';
    case GAP = 'gap';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn(self $case): string => $case->value,
            self::cases(),
        );
    }
}
