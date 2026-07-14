<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A provider of golden prompt sets a consuming extension contributes for
 * quality evaluation (ADR-060).
 *
 * Implementations are discovered via the `nr_llm.golden_prompt_set` DI tag
 * (auto-applied by AutoconfigureTag, mirroring
 * ConfigurationPresetProviderInterface / ToolInterface) and collected by
 * GoldenPromptSetRegistry through a tagged iterator. A consuming extension
 * only needs to implement this interface and register its class as a
 * service — no further wiring.
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface GoldenPromptSetProviderInterface
{
    public const TAG_NAME = 'nr_llm.golden_prompt_set';

    /**
     * The golden prompt sets this extension declares.
     *
     * @return list<GoldenPromptSet>
     */
    public function getGoldenPromptSets(): array;
}
