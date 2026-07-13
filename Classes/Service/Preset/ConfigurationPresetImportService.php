<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Enum\ModelSelectionMode;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Imports a declared configuration preset as a criteria-mode
 * LlmConfiguration record (ADR-056).
 *
 * `preflight()` answers whether the preset's requirements can be satisfied
 * by the currently configured, active models — checked through the same
 * ModelSelectionService that later resolves the record at runtime, so the
 * answer cannot drift from the runtime behaviour. `import()` refuses a
 * duplicate identifier and an unsatisfiable preset, then creates the record
 * in criteria selection mode with the preset's checksum stored for
 * change detection.
 */
final readonly class ConfigurationPresetImportService
{
    public function __construct(
        private ModelSelectionServiceInterface $modelSelectionService,
        private LlmConfigurationRepository $configurationRepository,
        private PersistenceManagerInterface $persistenceManager,
    ) {}

    /**
     * Check whether the preset's criteria match at least one active model.
     */
    public function preflight(ConfigurationPreset $preset): PresetPreflightResult
    {
        $model = $this->modelSelectionService->findMatchingModel($preset->criteria->toArray());
        if ($model !== null) {
            $label = $model->getName() !== '' ? $model->getName() : $model->getModelId();

            return PresetPreflightResult::satisfiable($label);
        }

        return PresetPreflightResult::unsatisfiable($this->determineMissingRequirement($preset->criteria));
    }

    /**
     * Import the preset as an active, criteria-mode configuration record.
     *
     * @throws InvalidArgumentException when a configuration with the preset's
     *                                  identifier already exists, or when no
     *                                  active model satisfies the criteria
     */
    public function import(ConfigurationPreset $preset): LlmConfiguration
    {
        if ($this->configurationRepository->findOneByIdentifier($preset->identifier) !== null) {
            throw new InvalidArgumentException(
                sprintf('A configuration with the identifier "%s" already exists.', $preset->identifier),
                1789347005,
            );
        }

        $preflight = $this->preflight($preset);
        if (!$preflight->satisfiable) {
            throw new InvalidArgumentException(
                sprintf(
                    'Preset "%s" cannot be imported: no active model satisfies the requirement (%s).',
                    $preset->identifier,
                    (string)$preflight->missingRequirement,
                ),
                1789347006,
            );
        }

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($preset->identifier);
        $configuration->setName($preset->name);
        $configuration->setDescription($preset->description);
        $configuration->setModelSelectionMode(ModelSelectionMode::CRITERIA->value);
        $configuration->setModelSelectionCriteriaDTO($preset->criteria);
        if ($preset->systemPrompt !== null) {
            $configuration->setSystemPrompt($preset->systemPrompt);
        }
        if ($preset->temperature !== null) {
            $configuration->setTemperature($preset->temperature);
        }
        if ($preset->maxTokens !== null) {
            $configuration->setMaxTokens($preset->maxTokens);
        }
        if ($preset->maxRequestsPerDay !== null) {
            $configuration->setMaxRequestsPerDay($preset->maxRequestsPerDay);
        }
        if ($preset->maxTokensPerDay !== null) {
            $configuration->setMaxTokensPerDay($preset->maxTokensPerDay);
        }
        if ($preset->maxCostPerDay !== null) {
            $configuration->setMaxCostPerDay($preset->maxCostPerDay);
        }
        if ($preset->allowedToolGroups !== []) {
            $configuration->setAllowedToolGroups(implode(',', $preset->allowedToolGroups));
        }
        $configuration->setIsActive(true);
        $configuration->setPresetChecksum($preset->checksum());

        $this->configurationRepository->add($configuration);
        $this->persistenceManager->persistAll();

        return $configuration;
    }

    /**
     * Name the first criterion that eliminates every candidate, by adding
     * the preset's constraints one at a time in a fixed order (capabilities,
     * adapter types, context length, cost ceiling).
     */
    private function determineMissingRequirement(ModelSelectionCriteria $criteria): string
    {
        $narrowed = ['capabilities' => $criteria->capabilities];
        if ($this->modelSelectionService->findCandidates($narrowed) === []) {
            return 'capabilities: ' . implode(', ', $criteria->capabilities);
        }

        if ($criteria->adapterTypes !== []) {
            $narrowed['adapterTypes'] = $criteria->adapterTypes;
            if ($this->modelSelectionService->findCandidates($narrowed) === []) {
                return 'adapter types: ' . implode(', ', $criteria->adapterTypes);
            }
        }

        if ($criteria->minContextLength > 0) {
            $narrowed['minContextLength'] = $criteria->minContextLength;
            if ($this->modelSelectionService->findCandidates($narrowed) === []) {
                return 'minimum context length: ' . $criteria->minContextLength;
            }
        }

        if ($criteria->maxCostInput > 0) {
            $narrowed['maxCostInput'] = $criteria->maxCostInput;
            if ($this->modelSelectionService->findCandidates($narrowed) === []) {
                return 'maximum input cost: ' . $criteria->maxCostInput;
            }
        }

        return 'combined criteria';
    }
}
