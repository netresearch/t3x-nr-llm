<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\DTO;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;

/**
 * Data Transfer Object for model selection criteria.
 *
 * Encapsulates the criteria used for dynamic model selection,
 * including required capabilities, provider types, and cost constraints.
 */
final readonly class ModelSelectionCriteria implements JsonSerializable
{
    /**
     * @param list<string> $capabilities     Required model capabilities
     * @param list<string> $adapterTypes     Allowed provider adapter types
     * @param int          $minContextLength Minimum context length requirement
     * @param int          $maxCostInput     Maximum input cost (in cents per 1M tokens)
     * @param bool         $preferLowestCost Whether to prefer the lowest cost model
     */
    public function __construct(
        public array $capabilities = [],
        public array $adapterTypes = [],
        public int $minContextLength = 0,
        public int $maxCostInput = 0,
        public bool $preferLowestCost = false,
    ) {}

    /**
     * Create from array.
     *
     * @param array{capabilities?: list<string>, adapterTypes?: list<string>, minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            capabilities: $data['capabilities'] ?? [],
            adapterTypes: $data['adapterTypes'] ?? [],
            minContextLength: $data['minContextLength'] ?? 0,
            maxCostInput: $data['maxCostInput'] ?? 0,
            preferLowestCost: $data['preferLowestCost'] ?? false,
        );
    }

    /**
     * Create from JSON string.
     */
    public static function fromJson(string $json): self
    {
        if ($json === '') {
            return new self();
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new self();
        }
        /** @var array{capabilities?: list<string>, adapterTypes?: list<string>, minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $data */
        return self::fromArray($data);
    }

    /**
     * Convert to array.
     *
     * @return array{capabilities: list<string>, adapterTypes: list<string>, minContextLength: int, maxCostInput: int, preferLowestCost: bool}
     */
    public function toArray(): array
    {
        return [
            'capabilities' => $this->capabilities,
            'adapterTypes' => $this->adapterTypes,
            'minContextLength' => $this->minContextLength,
            'maxCostInput' => $this->maxCostInput,
            'preferLowestCost' => $this->preferLowestCost,
        ];
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{capabilities: list<string>, adapterTypes: list<string>, minContextLength: int, maxCostInput: int, preferLowestCost: bool}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Check if any criteria are defined.
     */
    public function hasCriteria(): bool
    {
        return $this->capabilities !== []
            || $this->adapterTypes !== []
            || $this->minContextLength > 0
            || $this->maxCostInput > 0;
    }

    /**
     * Check if a specific capability is required.
     */
    public function requiresCapability(string|ModelCapability $capability): bool
    {
        $capabilityValue = $capability instanceof ModelCapability ? $capability->value : $capability;
        return in_array($capabilityValue, $this->capabilities, true);
    }

    /**
     * Check if a specific adapter type is allowed.
     */
    public function allowsAdapterType(string $adapterType): bool
    {
        if ($this->adapterTypes === []) {
            return true; // No restriction means all are allowed
        }
        return in_array($adapterType, $this->adapterTypes, true);
    }

    /**
     * Create a new instance with an additional required capability.
     */
    public function withCapability(string|ModelCapability $capability): self
    {
        $capabilityValue = $capability instanceof ModelCapability ? $capability->value : $capability;
        if (in_array($capabilityValue, $this->capabilities, true)) {
            return $this;
        }
        return new self(
            capabilities: [...$this->capabilities, $capabilityValue],
            adapterTypes: $this->adapterTypes,
            minContextLength: $this->minContextLength,
            maxCostInput: $this->maxCostInput,
            preferLowestCost: $this->preferLowestCost,
        );
    }

    /**
     * Create a new instance with an additional allowed adapter type.
     */
    public function withAdapterType(string $adapterType): self
    {
        if (in_array($adapterType, $this->adapterTypes, true)) {
            return $this;
        }
        return new self(
            capabilities: $this->capabilities,
            adapterTypes: [...$this->adapterTypes, $adapterType],
            minContextLength: $this->minContextLength,
            maxCostInput: $this->maxCostInput,
            preferLowestCost: $this->preferLowestCost,
        );
    }

    /**
     * Create a new instance with minimum context length.
     */
    public function withMinContextLength(int $minContextLength): self
    {
        return new self(
            capabilities: $this->capabilities,
            adapterTypes: $this->adapterTypes,
            minContextLength: $minContextLength,
            maxCostInput: $this->maxCostInput,
            preferLowestCost: $this->preferLowestCost,
        );
    }

    /**
     * Create a new instance with maximum input cost.
     */
    public function withMaxCostInput(int $maxCostInput): self
    {
        return new self(
            capabilities: $this->capabilities,
            adapterTypes: $this->adapterTypes,
            minContextLength: $this->minContextLength,
            maxCostInput: $maxCostInput,
            preferLowestCost: $this->preferLowestCost,
        );
    }

    /**
     * Create a new instance with lowest cost preference.
     */
    public function withLowestCostPreference(bool $preferLowestCost = true): self
    {
        return new self(
            capabilities: $this->capabilities,
            adapterTypes: $this->adapterTypes,
            minContextLength: $this->minContextLength,
            maxCostInput: $this->maxCostInput,
            preferLowestCost: $preferLowestCost,
        );
    }
}
