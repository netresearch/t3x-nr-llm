<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Preset\Fixtures;

use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetProviderInterface;

/**
 * Minimal in-memory ConfigurationPresetProviderInterface double used by the
 * preset registry and controller unit tests.
 *
 * Carries the presets it was constructed with, so a test controls exactly
 * which declarations the registry collects without touching the DI container.
 */
final readonly class FixturePresetProvider implements ConfigurationPresetProviderInterface
{
    /**
     * @param list<ConfigurationPreset> $presets
     */
    public function __construct(
        private array $presets,
    ) {}

    /**
     * @return list<ConfigurationPreset>
     */
    public function getPresets(): array
    {
        return $this->presets;
    }
}
