<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Domain model for LLM Models.
 *
 * Represents an available LLM model with capabilities, pricing, and provider relation.
 * Models can be reused across multiple configurations.
 */
class Model extends AbstractEntity
{
    /**
     * Capability constants.
     *
     * @deprecated Use ModelCapability enum instead
     */
    public const CAPABILITY_CHAT = 'chat';
    /** @deprecated Use ModelCapability enum instead */
    public const CAPABILITY_COMPLETION = 'completion';
    /** @deprecated Use ModelCapability enum instead */
    public const CAPABILITY_EMBEDDINGS = 'embeddings';
    /** @deprecated Use ModelCapability enum instead */
    public const CAPABILITY_VISION = 'vision';
    /** @deprecated Use ModelCapability enum instead */
    public const CAPABILITY_STREAMING = 'streaming';
    /** @deprecated Use ModelCapability enum instead */
    public const CAPABILITY_TOOLS = 'tools';
    /** @deprecated Use ModelCapability enum instead */
    public const CAPABILITY_JSON_MODE = 'json_mode';
    /** @deprecated Use ModelCapability enum instead */
    public const CAPABILITY_AUDIO = 'audio';

    protected string $identifier = '';
    protected string $name = '';
    protected string $description = '';
    protected ?Provider $provider = null;
    protected string $modelId = '';
    protected int $contextLength = 0;
    protected int $maxOutputTokens = 0;
    protected string $capabilities = '';
    protected int $defaultTimeout = 120;
    protected int $costInput = 0;
    protected int $costOutput = 0;
    protected bool $isActive = true;
    protected bool $isDefault = false;
    protected int $sorting = 0;
    protected int $tstamp = 0;
    protected int $crdate = 0;
    protected int $cruserId = 0;

    // ========================================
    // Getters
    // ========================================

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getProvider(): ?Provider
    {
        return $this->provider;
    }

    public function getModelId(): string
    {
        return $this->modelId;
    }

    public function getContextLength(): int
    {
        return $this->contextLength;
    }

    public function getMaxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    public function getCapabilities(): string
    {
        return $this->capabilities;
    }

    /**
     * Get capabilities as array.
     *
     * @return string[]
     */
    public function getCapabilitiesArray(): array
    {
        if ($this->capabilities === '') {
            return [];
        }
        return array_map(trim(...), explode(',', $this->capabilities));
    }

    /**
     * Get capabilities as enum array.
     *
     * @return list<ModelCapability>
     */
    public function getCapabilitiesAsEnums(): array
    {
        $enums = [];
        foreach ($this->getCapabilitiesArray() as $capability) {
            $enum = ModelCapability::tryFrom($capability);
            if ($enum !== null) {
                $enums[] = $enum;
            }
        }
        return $enums;
    }

    public function getDefaultTimeout(): int
    {
        return $this->defaultTimeout;
    }

    public function getCostInput(): int
    {
        return $this->costInput;
    }

    public function getCostOutput(): int
    {
        return $this->costOutput;
    }

    /**
     * Get input cost in dollars per 1M tokens.
     */
    public function getCostInputDollars(): float
    {
        return $this->costInput / 100;
    }

    /**
     * Get output cost in dollars per 1M tokens.
     */
    public function getCostOutputDollars(): float
    {
        return $this->costOutput / 100;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getIsDefault(): bool
    {
        return $this->isDefault;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getSorting(): int
    {
        return $this->sorting;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function getCrdate(): int
    {
        return $this->crdate;
    }

    public function getCruserId(): int
    {
        return $this->cruserId;
    }

    // ========================================
    // Setters
    // ========================================

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setProvider(?Provider $provider): void
    {
        $this->provider = $provider;
    }

    public function setModelId(string $modelId): void
    {
        $this->modelId = $modelId;
    }

    public function setContextLength(int $contextLength): void
    {
        $this->contextLength = max(0, $contextLength);
    }

    public function setMaxOutputTokens(int $maxOutputTokens): void
    {
        $this->maxOutputTokens = max(0, $maxOutputTokens);
    }

    public function setCapabilities(string $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    /**
     * Set capabilities from array.
     *
     * @param string[] $capabilities
     */
    public function setCapabilitiesArray(array $capabilities): void
    {
        $this->capabilities = implode(',', array_map(trim(...), $capabilities));
    }

    public function setDefaultTimeout(int $defaultTimeout): void
    {
        $this->defaultTimeout = max(0, $defaultTimeout);
    }

    public function setCostInput(int $costInput): void
    {
        $this->costInput = max(0, $costInput);
    }

    public function setCostOutput(int $costOutput): void
    {
        $this->costOutput = max(0, $costOutput);
    }

    /**
     * Set input cost in dollars per 1M tokens.
     */
    public function setCostInputDollars(float $dollars): void
    {
        $this->costInput = (int)round($dollars * 100);
    }

    /**
     * Set output cost in dollars per 1M tokens.
     */
    public function setCostOutputDollars(float $dollars): void
    {
        $this->costOutput = (int)round($dollars * 100);
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setIsDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function setSorting(int $sorting): void
    {
        $this->sorting = $sorting;
    }

    // ========================================
    // Capability Methods
    // ========================================

    /**
     * Check if model has a specific capability.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->getCapabilitiesArray(), true);
    }

    /**
     * Add a capability.
     */
    public function addCapability(string $capability): void
    {
        $caps = $this->getCapabilitiesArray();
        if (!in_array($capability, $caps, true)) {
            $caps[] = $capability;
            $this->setCapabilitiesArray($caps);
        }
    }

    /**
     * Remove a capability.
     */
    public function removeCapability(string $capability): void
    {
        $caps = array_filter(
            $this->getCapabilitiesArray(),
            static fn(string $cap): bool => $cap !== $capability,
        );
        $this->setCapabilitiesArray($caps);
    }

    public function supportsChat(): bool
    {
        return $this->hasCapability(ModelCapability::CHAT->value);
    }

    public function supportsCompletion(): bool
    {
        return $this->hasCapability(ModelCapability::COMPLETION->value);
    }

    public function supportsEmbeddings(): bool
    {
        return $this->hasCapability(ModelCapability::EMBEDDINGS->value);
    }

    public function supportsVision(): bool
    {
        return $this->hasCapability(ModelCapability::VISION->value);
    }

    public function supportsStreaming(): bool
    {
        return $this->hasCapability(ModelCapability::STREAMING->value);
    }

    public function supportsTools(): bool
    {
        return $this->hasCapability(ModelCapability::TOOLS->value);
    }

    public function supportsJsonMode(): bool
    {
        return $this->hasCapability(ModelCapability::JSON_MODE->value);
    }

    public function supportsAudio(): bool
    {
        return $this->hasCapability(ModelCapability::AUDIO->value);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Get all available capabilities.
     *
     * @return array<string, string>
     */
    public static function getAllCapabilities(): array
    {
        return [
            ModelCapability::CHAT->value => 'Chat',
            ModelCapability::COMPLETION->value => 'Completion',
            ModelCapability::EMBEDDINGS->value => 'Embeddings',
            ModelCapability::VISION->value => 'Vision',
            ModelCapability::STREAMING->value => 'Streaming',
            ModelCapability::TOOLS->value => 'Tool Use',
            ModelCapability::JSON_MODE->value => 'JSON Mode',
            ModelCapability::AUDIO->value => 'Audio',
        ];
    }

    /**
     * Get display name including provider.
     */
    public function getDisplayName(): string
    {
        if ($this->provider !== null) {
            return sprintf('%s (%s)', $this->name, $this->provider->getName());
        }
        return $this->name;
    }

    /**
     * Get formatted context length (e.g., "128K").
     */
    public function getFormattedContextLength(): string
    {
        if ($this->contextLength === 0) {
            return 'Unknown';
        }
        if ($this->contextLength >= 1000000) {
            return sprintf('%.1fM', $this->contextLength / 1000000);
        }
        if ($this->contextLength >= 1000) {
            return sprintf('%dK', (int)($this->contextLength / 1000));
        }
        return (string)$this->contextLength;
    }

    /**
     * Estimate cost for given token usage.
     *
     * @return float Cost in dollars
     */
    public function estimateCost(int $inputTokens, int $outputTokens): float
    {
        $inputCost = ($inputTokens / 1000000) * $this->getCostInputDollars();
        $outputCost = ($outputTokens / 1000000) * $this->getCostOutputDollars();
        return $inputCost + $outputCost;
    }

    /**
     * Check if model has pricing information.
     */
    public function hasPricing(): bool
    {
        return $this->costInput > 0 || $this->costOutput > 0;
    }
}
