<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A provider of golden question sets a consuming extension contributes for
 * retrieval-quality evaluation (ADR-072), mirroring
 * {@see GoldenPromptSetProviderInterface}.
 *
 * Implementations are discovered via the `nr_llm.golden_question_set` DI
 * tag (auto-applied by AutoconfigureTag) and collected by
 * GoldenQuestionSetRegistry through a tagged iterator. A consuming
 * extension only needs to implement this interface and register its class
 * as a service — no further wiring.
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface GoldenQuestionSetProviderInterface
{
    public const TAG_NAME = 'nr_llm.golden_question_set';

    /**
     * The golden question sets this extension declares.
     *
     * @return list<GoldenQuestionSet>
     */
    public function getGoldenQuestionSets(): array;
}
