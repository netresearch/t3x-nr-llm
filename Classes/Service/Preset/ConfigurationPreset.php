<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A declared configuration preset — the REQUIREMENTS a consuming extension
 * has for an LlmConfiguration record, never a concrete provider, model, or
 * API key (ADR-056).
 *
 * The identifier is namespaced (e.g. `nr_ai_search.chat`) so presets from
 * different extensions cannot collide. The criteria express what the
 * resolved model must be capable of; the imported record runs in criteria
 * selection mode and is resolved by ModelSelectionService against whatever
 * the admin configured.
 *
 * All remaining fields are optional seeds for the created record; `null`
 * (or `[]` for the tool groups) keeps the column default.
 */
final readonly class ConfigurationPreset
{
    /**
     * Lowercase dot-namespaced identifier: segments of `[a-z0-9_]`
     * separated by single dots, e.g. `nr_ai_search.chat`.
     */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/';

    /** The `tx_nrllm_configuration.identifier` column is varchar(100). */
    private const IDENTIFIER_MAX_LENGTH = 100;

    /** The `tx_nrllm_configuration.name` column is varchar(255). */
    private const NAME_MAX_LENGTH = 255;

    /**
     * @param string                 $identifier        Namespaced record identifier (lowercase, `[a-z0-9_.]`)
     * @param string                 $name              Human-readable name for the created record
     * @param string                 $description       What the consuming extension uses the configuration for
     * @param ModelSelectionCriteria $criteria          Model requirements (at least one capability)
     * @param string|null            $systemPrompt      Optional system prompt seed
     * @param float|null             $temperature       Optional sampling temperature seed
     * @param int|null               $maxTokens         Optional max-tokens seed
     * @param int|null               $maxRequestsPerDay Optional daily request budget
     * @param int|null               $maxTokensPerDay   Optional daily token budget
     * @param float|null             $maxCostPerDay     Optional daily cost budget
     * @param list<string>           $allowedToolGroups Permitted tool groups; empty = column default (all groups)
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public ModelSelectionCriteria $criteria,
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?int $maxRequestsPerDay = null,
        public ?int $maxTokensPerDay = null,
        public ?float $maxCostPerDay = null,
        public array $allowedToolGroups = [],
    ) {
        if ($identifier === '' || preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid preset identifier "%s": expected lowercase segments of [a-z0-9_] separated by dots, e.g. "nr_ai_search.chat".',
                    $identifier,
                ),
                1789347001,
            );
        }
        if (strlen($identifier) > self::IDENTIFIER_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid preset identifier "%s": must not exceed %d characters (identifier column limit).',
                    $identifier,
                    self::IDENTIFIER_MAX_LENGTH,
                ),
                1789347002,
            );
        }
        if ($criteria->capabilities === []) {
            throw new InvalidArgumentException(
                sprintf(
                    'Preset "%s" declares no required capability: the criteria must require at least one model capability.',
                    $identifier,
                ),
                1789347003,
            );
        }
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Preset "%s" name must be 1-%d characters (name column limit); got %d.',
                    $identifier,
                    self::NAME_MAX_LENGTH,
                    mb_strlen($name),
                ),
                1789347005,
            );
        }
    }

    /**
     * SHA-256 checksum over a canonical JSON encoding (recursively sorted
     * keys) of every declared field, so a changed declaration in the
     * consuming extension is detectable against the stored checksum of an
     * already-imported record.
     */
    public function checksum(): string
    {
        $data = [
            'allowedToolGroups' => $this->allowedToolGroups,
            'criteria' => $this->criteria->toArray(),
            'description' => $this->description,
            'identifier' => $this->identifier,
            'maxCostPerDay' => $this->maxCostPerDay,
            'maxRequestsPerDay' => $this->maxRequestsPerDay,
            'maxTokens' => $this->maxTokens,
            'maxTokensPerDay' => $this->maxTokensPerDay,
            'name' => $this->name,
            'systemPrompt' => $this->systemPrompt,
            'temperature' => $this->temperature,
        ];
        return hash('sha256', json_encode($this->sortKeysRecursively($data), JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function sortKeysRecursively(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortKeysRecursively($value);
            }
        }

        return $data;
    }
}
