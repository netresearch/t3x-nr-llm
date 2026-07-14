<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;

/**
 * Computes the field-level diff between a declared configuration preset and
 * the imported record it manages (ADR-056 update flow).
 *
 * The diff is one-directional: declared value versus the record's *current*
 * value, so it names exactly what a re-confirmed update would overwrite. It
 * only ever touches fields an update applies:
 *
 * * name, description and the model-selection criteria are core fields — the
 *   declaration always wins, so any difference is reported.
 * * the optional seeds (system prompt, temperature, max tokens, the three
 *   daily budgets, allowed tool groups) are reported only when the
 *   declaration carries a value (non-null, non-empty list): a seed the
 *   declaration left open never resets the record, so it is not a change.
 *
 * Admin-owned fields (active state, default flag, backend groups, fallback
 * chain) are structurally absent — the preset value object has no field for
 * them, so they can never appear here.
 */
final readonly class ConfigurationPresetDiffService
{
    /**
     * Temperatures and cost ceilings are stored as `decimal(_,2)` columns, so
     * compare at that scale: a declared value is a change only when it differs
     * from the stored value in the second decimal place or beyond.
     */
    private const FLOAT_SCALE = 2;

    public function diff(ConfigurationPreset $preset, LlmConfiguration $record): PresetDiff
    {
        $changes = [];

        $this->addStringChange($changes, 'name', $record->getName(), $preset->name);
        $this->addStringChange($changes, 'description', $record->getDescription(), $preset->description);

        $this->addCriteriaChanges($changes, $preset, $record);

        if ($preset->systemPrompt !== null) {
            $this->addStringChange($changes, 'systemPrompt', $record->getSystemPrompt(), $preset->systemPrompt);
        }
        if ($preset->temperature !== null) {
            $this->addFloatChange($changes, 'temperature', $record->getTemperature(), $preset->temperature);
        }
        if ($preset->maxTokens !== null) {
            $this->addIntChange($changes, 'maxTokens', $record->getMaxTokens(), $preset->maxTokens);
        }
        if ($preset->maxRequestsPerDay !== null) {
            $this->addIntChange($changes, 'maxRequestsPerDay', $record->getMaxRequestsPerDay(), $preset->maxRequestsPerDay);
        }
        if ($preset->maxTokensPerDay !== null) {
            $this->addIntChange($changes, 'maxTokensPerDay', $record->getMaxTokensPerDay(), $preset->maxTokensPerDay);
        }
        if ($preset->maxCostPerDay !== null) {
            $this->addFloatChange($changes, 'maxCostPerDay', $record->getMaxCostPerDay(), $preset->maxCostPerDay);
        }
        if ($preset->allowedToolGroups !== []) {
            $this->addToolGroupsChange($changes, $record->getAllowedToolGroupsList(), $preset->allowedToolGroups);
        }

        return new PresetDiff($preset->identifier, $preset->name, $changes);
    }

    /**
     * @param list<PresetFieldDiff> $changes
     */
    private function addCriteriaChanges(array &$changes, ConfigurationPreset $preset, LlmConfiguration $record): void
    {
        $declared = $preset->criteria->toArray();
        $current = $record->getModelSelectionCriteriaDTO()->toArray();

        $this->addListChange($changes, 'criteria.capabilities', $current['capabilities'], $declared['capabilities']);
        $this->addListChange($changes, 'criteria.adapterTypes', $current['adapterTypes'], $declared['adapterTypes']);
        $this->addIntChange($changes, 'criteria.minContextLength', $current['minContextLength'], $declared['minContextLength']);
        $this->addIntChange($changes, 'criteria.maxCostInput', $current['maxCostInput'], $declared['maxCostInput']);
        $this->addBoolChange($changes, 'criteria.preferLowestCost', $current['preferLowestCost'], $declared['preferLowestCost']);
    }

    /**
     * @param list<PresetFieldDiff> $changes
     */
    private function addStringChange(array &$changes, string $field, string $current, string $declared): void
    {
        if ($current !== $declared) {
            $changes[] = new PresetFieldDiff($field, $current, $declared);
        }
    }

    /**
     * @param list<PresetFieldDiff> $changes
     */
    private function addIntChange(array &$changes, string $field, int $current, int $declared): void
    {
        if ($current !== $declared) {
            $changes[] = new PresetFieldDiff($field, (string)$current, (string)$declared);
        }
    }

    /**
     * @param list<PresetFieldDiff> $changes
     */
    private function addBoolChange(array &$changes, string $field, bool $current, bool $declared): void
    {
        if ($current !== $declared) {
            $changes[] = new PresetFieldDiff($field, $current ? 'true' : 'false', $declared ? 'true' : 'false');
        }
    }

    /**
     * @param list<PresetFieldDiff> $changes
     */
    private function addFloatChange(array &$changes, string $field, float $current, float $declared): void
    {
        $currentScaled = round($current, self::FLOAT_SCALE);
        $declaredScaled = round($declared, self::FLOAT_SCALE);
        if (abs($currentScaled - $declaredScaled) >= 0.5 * 10 ** -self::FLOAT_SCALE) {
            $changes[] = new PresetFieldDiff(
                $field,
                number_format($currentScaled, self::FLOAT_SCALE),
                number_format($declaredScaled, self::FLOAT_SCALE),
            );
        }
    }

    /**
     * @param list<PresetFieldDiff> $changes
     * @param list<string>          $current
     * @param list<string>          $declared
     */
    private function addListChange(array &$changes, string $field, array $current, array $declared): void
    {
        if ($current !== $declared) {
            $changes[] = new PresetFieldDiff($field, implode(', ', $current), implode(', ', $declared));
        }
    }

    /**
     * The record stores tool groups as a CSV column, the declaration as a
     * list; normalise both to a sorted list before comparing so a pure
     * reordering is not reported. The display keeps each side's own order.
     *
     * @param list<PresetFieldDiff> $changes
     * @param list<string>          $current
     * @param list<string>          $declared
     */
    private function addToolGroupsChange(array &$changes, array $current, array $declared): void
    {
        $currentSorted = $current;
        $declaredSorted = $declared;
        sort($currentSorted);
        sort($declaredSorted);
        if ($currentSorted !== $declaredSorted) {
            $changes[] = new PresetFieldDiff('allowedToolGroups', implode(', ', $current), implode(', ', $declared));
        }
    }
}
