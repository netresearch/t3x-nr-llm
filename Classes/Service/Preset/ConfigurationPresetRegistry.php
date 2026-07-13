<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

use LogicException;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every DI-tagged ConfigurationPresetProviderInterface and exposes
 * the declared presets (ADR-056).
 *
 * Providers are injected through the `nr_llm.configuration_preset` tagged
 * iterator (mirroring ToolRegistry) and their presets indexed by identifier;
 * a duplicate identifier across providers is a developer error and fails
 * fast with a LogicException at construction time.
 *
 * `pending()` narrows the declared set to presets whose identifier has no
 * `tx_nrllm_configuration` record yet — the list a backend admin sees and
 * imports from.
 */
final class ConfigurationPresetRegistry
{
    /** @var array<string, ConfigurationPreset> */
    private array $byIdentifier = [];

    /**
     * @param iterable<ConfigurationPresetProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(ConfigurationPresetProviderInterface::TAG_NAME)]
        iterable $providers,
        private readonly LlmConfigurationRepository $configurationRepository,
    ) {
        foreach ($providers as $provider) {
            foreach ($provider->getPresets() as $preset) {
                if (isset($this->byIdentifier[$preset->identifier])) {
                    throw new LogicException(
                        sprintf('Duplicate configuration preset identifier "%s".', $preset->identifier),
                        1789347004,
                    );
                }
                $this->byIdentifier[$preset->identifier] = $preset;
            }
        }
    }

    /**
     * @return list<ConfigurationPreset>
     */
    public function all(): array
    {
        return array_values($this->byIdentifier);
    }

    public function findByIdentifier(string $identifier): ?ConfigurationPreset
    {
        return $this->byIdentifier[$identifier] ?? null;
    }

    /**
     * Declared presets whose identifier has no configuration record yet.
     *
     * @return list<ConfigurationPreset>
     */
    public function pending(): array
    {
        $pending = [];
        foreach ($this->byIdentifier as $identifier => $preset) {
            if ($this->configurationRepository->findOneByIdentifier($identifier) === null) {
                $pending[] = $preset;
            }
        }

        return $pending;
    }
}
