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
 *
 * `previewUpdate()` / `update()` implement the drift resolution flow: when a
 * consuming extension changes a preset declaration, the admin reviews the
 * diff against the imported record and re-confirms. An update applies the
 * declared name, description, criteria and non-null seeds, then re-stamps the
 * checksum so the drift hint clears; it never touches admin-owned fields
 * (active state, default flag, backend groups, fallback chain) and refuses a
 * record whose selection mode the admin switched away from criteria.
 */
final readonly class ConfigurationPresetImportService
{
    /** An update was asked for a record whose identifier is not the preset's. */
    public const CODE_UPDATE_IDENTIFIER_MISMATCH = 1789347007;

    /** The record is up to date or not preset-managed — nothing to update. */
    public const CODE_UPDATE_NOT_DRIFTED = 1789347008;

    /** The admin switched the record off criteria mode; an update is refused. */
    public const CODE_UPDATE_MODE_SWITCHED = 1789347009;

    /** No active model satisfies the updated criteria. */
    public const CODE_UPDATE_UNSATISFIABLE = 1789347010;

    public function __construct(
        private ModelSelectionServiceInterface $modelSelectionService,
        private LlmConfigurationRepository $configurationRepository,
        private PersistenceManagerInterface $persistenceManager,
        private ConfigurationPresetDiffService $diffService,
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
     * Diff the declared preset against the imported record, refusing when an
     * update could not be applied (record not drifted, mode switched away from
     * criteria, or the updated criteria unsatisfiable). Read-only — for the
     * re-confirm preview.
     *
     * @throws InvalidArgumentException with one of the CODE_UPDATE_* codes
     */
    public function previewUpdate(ConfigurationPreset $preset, LlmConfiguration $record): PresetDiff
    {
        $this->assertUpdatable($preset, $record);

        return $this->diffService->diff($preset, $record);
    }

    /**
     * Apply a changed preset declaration to its imported record after the
     * admin re-confirmed the diff.
     *
     * Applies the declared name, description and criteria (the declaration
     * always wins) and each non-null seed, then re-stamps the record's stored
     * checksum to the current declaration so the drift hint clears. Admin-owned
     * fields (active state, default flag, backend groups, fallback chain) are
     * left untouched. Returns the diff that was applied.
     *
     * @throws InvalidArgumentException with one of the CODE_UPDATE_* codes
     */
    public function update(ConfigurationPreset $preset, LlmConfiguration $record): PresetDiff
    {
        $this->assertUpdatable($preset, $record);

        $diff = $this->diffService->diff($preset, $record);

        $record->setName($preset->name);
        $record->setDescription($preset->description);
        $record->setModelSelectionCriteriaDTO($preset->criteria);
        if ($preset->systemPrompt !== null) {
            $record->setSystemPrompt($preset->systemPrompt);
        }
        if ($preset->temperature !== null) {
            $record->setTemperature($preset->temperature);
        }
        if ($preset->maxTokens !== null) {
            $record->setMaxTokens($preset->maxTokens);
        }
        if ($preset->maxRequestsPerDay !== null) {
            $record->setMaxRequestsPerDay($preset->maxRequestsPerDay);
        }
        if ($preset->maxTokensPerDay !== null) {
            $record->setMaxTokensPerDay($preset->maxTokensPerDay);
        }
        if ($preset->maxCostPerDay !== null) {
            $record->setMaxCostPerDay($preset->maxCostPerDay);
        }
        if ($preset->allowedToolGroups !== []) {
            $record->setAllowedToolGroups(implode(',', $preset->allowedToolGroups));
        }
        $record->setPresetChecksum($preset->checksum());

        $this->configurationRepository->update($record);
        $this->persistenceManager->persistAll();

        return $diff;
    }

    /**
     * Refuse an update the flow must not perform.
     *
     * @throws InvalidArgumentException with one of the CODE_UPDATE_* codes
     */
    private function assertUpdatable(ConfigurationPreset $preset, LlmConfiguration $record): void
    {
        if ($record->getIdentifier() !== $preset->identifier) {
            throw new InvalidArgumentException(
                sprintf(
                    'Configuration "%s" does not belong to preset "%s".',
                    $record->getIdentifier(),
                    $preset->identifier,
                ),
                self::CODE_UPDATE_IDENTIFIER_MISMATCH,
            );
        }

        $storedChecksum = $record->getPresetChecksum();
        if ($storedChecksum === '' || $storedChecksum === $preset->checksum()) {
            throw new InvalidArgumentException(
                sprintf(
                    'Preset "%s" has no drifted configuration to update: the record is up to date or was not imported from a preset.',
                    $preset->identifier,
                ),
                self::CODE_UPDATE_NOT_DRIFTED,
            );
        }

        if (!$record->usesCriteriaSelection()) {
            throw new InvalidArgumentException(
                sprintf(
                    'Configuration "%s" was switched to fixed model selection; updating the preset would override the administrator\'s model choice and is refused.',
                    $preset->identifier,
                ),
                self::CODE_UPDATE_MODE_SWITCHED,
            );
        }

        $preflight = $this->preflight($preset);
        if (!$preflight->satisfiable) {
            throw new InvalidArgumentException(
                sprintf(
                    'Preset "%s" cannot be updated: no active model satisfies the requirement (%s).',
                    $preset->identifier,
                    (string)$preflight->missingRequirement,
                ),
                self::CODE_UPDATE_UNSATISFIABLE,
            );
        }
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
