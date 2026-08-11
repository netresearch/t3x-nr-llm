<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetProviderInterface;

/**
 * Publishes every declared pack's configuration preset to the preset registry
 * (ADR-163).
 *
 * Without this bridge a pack's configuration would exist in two worlds: created
 * by the pack installer, but invisible to the preset registry that owns
 * preflight, drift detection and the re-confirm update flow (ADR-056). With it
 * there is one declaration and one lifecycle — the pack simply installs its
 * preset earlier and alongside its tasks and snippets.
 *
 * The visible consequence is intended: a pack's configuration also appears in
 * the Configuration module's pending-preset list, and an admin who imports it
 * there has installed exactly the pack's configuration, no more.
 */
final readonly class UseCasePackPresetProvider implements ConfigurationPresetProviderInterface
{
    public function __construct(
        private UseCasePackRegistry $registry,
    ) {}

    /**
     * @return list<ConfigurationPreset>
     */
    public function getPresets(): array
    {
        return array_map(
            static fn(UseCasePack $pack): ConfigurationPreset => $pack->configurationPreset,
            $this->registry->all(),
        );
    }
}
