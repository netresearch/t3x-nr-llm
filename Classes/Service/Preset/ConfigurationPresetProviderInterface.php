<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A provider of configuration presets a consuming extension needs.
 *
 * Implementations declare the LlmConfiguration records their extension
 * expects (by identifier, with model REQUIREMENTS expressed as
 * ModelSelectionCriteria — never a concrete provider, model, or API key).
 * They are discovered via the `nr_llm.configuration_preset` DI tag
 * (auto-applied by AutoconfigureTag, mirroring ToolInterface) and collected
 * by ConfigurationPresetRegistry through a tagged iterator.
 *
 * nr_llm lists undeclared-but-not-yet-imported presets as "pending"; a
 * backend admin imports one with a single confirmation. The imported record
 * is a criteria-mode configuration that ModelSelectionService resolves at
 * runtime against whatever providers/models the admin has configured
 * (ADR-056).
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface ConfigurationPresetProviderInterface
{
    public const TAG_NAME = 'nr_llm.configuration_preset';

    /**
     * The presets this extension declares.
     *
     * @return list<ConfigurationPreset>
     */
    public function getPresets(): array;
}
