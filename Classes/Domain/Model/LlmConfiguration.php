<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Service\Option\ChatOptions;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Domain model for LLM configuration presets.
 *
 * Represents a named LLM configuration that administrators can create
 * and manage via backend module. Includes provider settings, model parameters,
 * usage limits, and access control.
 */
class LlmConfiguration extends AbstractEntity
{
    protected string $identifier = '';
    protected string $name = '';
    protected string $description = '';
    protected string $provider = '';
    protected string $model = '';
    protected string $translator = '';
    protected string $systemPrompt = '';
    protected float $temperature = 0.7;
    protected int $maxTokens = 1000;
    protected float $topP = 1.0;
    protected float $frequencyPenalty = 0.0;
    protected float $presencePenalty = 0.0;
    protected string $options = '';
    protected int $maxRequestsPerDay = 0;
    protected int $maxTokensPerDay = 0;
    protected float $maxCostPerDay = 0.0;
    protected bool $isActive = true;
    protected bool $isDefault = false;
    protected int $allowedGroups = 0;
    protected int $tstamp = 0;
    protected int $crdate = 0;
    protected int $cruserId = 0;

    /**
     * Allowed backend groups (MM relation).
     *
     * @var ObjectStorage<AbstractEntity>|null
     */
    protected ?ObjectStorage $beGroups = null;

    public function __construct()
    {
        $this->beGroups = new ObjectStorage();
    }

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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getTranslator(): string
    {
        return $this->translator;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    public function getTopP(): float
    {
        return $this->topP;
    }

    public function getFrequencyPenalty(): float
    {
        return $this->frequencyPenalty;
    }

    public function getPresencePenalty(): float
    {
        return $this->presencePenalty;
    }

    public function getOptions(): string
    {
        return $this->options;
    }

    /**
     * Get parsed options array.
     *
     * @return array<string, mixed>
     */
    public function getOptionsArray(): array
    {
        if ($this->options === '') {
            return [];
        }
        $decoded = json_decode($this->options, true);
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function getMaxRequestsPerDay(): int
    {
        return $this->maxRequestsPerDay;
    }

    public function getMaxTokensPerDay(): int
    {
        return $this->maxTokensPerDay;
    }

    public function getMaxCostPerDay(): float
    {
        return $this->maxCostPerDay;
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

    public function getAllowedGroups(): int
    {
        return $this->allowedGroups;
    }

    /**
     * @return ObjectStorage<AbstractEntity>|null
     */
    public function getBeGroups(): ?ObjectStorage
    {
        return $this->beGroups;
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

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function setTranslator(string $translator): void
    {
        $this->translator = $translator;
    }

    public function setSystemPrompt(string $systemPrompt): void
    {
        $this->systemPrompt = $systemPrompt;
    }

    public function setTemperature(float $temperature): void
    {
        $this->temperature = max(0.0, min(2.0, $temperature));
    }

    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = max(1, $maxTokens);
    }

    public function setTopP(float $topP): void
    {
        $this->topP = max(0.0, min(1.0, $topP));
    }

    public function setFrequencyPenalty(float $frequencyPenalty): void
    {
        $this->frequencyPenalty = max(-2.0, min(2.0, $frequencyPenalty));
    }

    public function setPresencePenalty(float $presencePenalty): void
    {
        $this->presencePenalty = max(-2.0, min(2.0, $presencePenalty));
    }

    public function setOptions(string $options): void
    {
        $this->options = $options;
    }

    /**
     * Set options from array.
     *
     * @param array<string, mixed> $options
     */
    public function setOptionsArray(array $options): void
    {
        $this->options = json_encode($options, JSON_THROW_ON_ERROR);
    }

    public function setMaxRequestsPerDay(int $maxRequestsPerDay): void
    {
        $this->maxRequestsPerDay = max(0, $maxRequestsPerDay);
    }

    public function setMaxTokensPerDay(int $maxTokensPerDay): void
    {
        $this->maxTokensPerDay = max(0, $maxTokensPerDay);
    }

    public function setMaxCostPerDay(float $maxCostPerDay): void
    {
        $this->maxCostPerDay = max(0.0, $maxCostPerDay);
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setIsDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function setAllowedGroups(int $allowedGroups): void
    {
        $this->allowedGroups = $allowedGroups;
    }

    /**
     * @param ObjectStorage<AbstractEntity>|null $beGroups
     */
    public function setBeGroups(?ObjectStorage $beGroups): void
    {
        $this->beGroups = $beGroups;
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Check if this configuration has usage limits enabled.
     */
    public function hasUsageLimits(): bool
    {
        return $this->maxRequestsPerDay > 0
            || $this->maxTokensPerDay > 0
            || $this->maxCostPerDay > 0;
    }

    /**
     * Check if configuration has access restrictions.
     */
    public function hasAccessRestrictions(): bool
    {
        return $this->allowedGroups > 0
            || ($this->beGroups !== null && $this->beGroups->count() > 0);
    }

    /**
     * Convert configuration to ChatOptions object.
     */
    public function toChatOptions(): ChatOptions
    {
        return new ChatOptions(
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            topP: $this->topP,
            frequencyPenalty: $this->frequencyPenalty,
            presencePenalty: $this->presencePenalty,
            systemPrompt: $this->systemPrompt !== '' ? $this->systemPrompt : null,
            provider: $this->provider !== '' ? $this->provider : null,
            model: $this->model !== '' ? $this->model : null,
        );
    }

    /**
     * Convert configuration to options array.
     *
     * @return array<string, mixed>
     */
    public function toOptionsArray(): array
    {
        $options = [
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'top_p' => $this->topP,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
        ];

        if ($this->systemPrompt !== '') {
            $options['system_prompt'] = $this->systemPrompt;
        }

        if ($this->provider !== '') {
            $options['provider'] = $this->provider;
        }

        if ($this->model !== '') {
            $options['model'] = $this->model;
        }

        if ($this->translator !== '') {
            $options['translator'] = $this->translator;
        }

        // Merge additional options
        $additionalOptions = $this->getOptionsArray();
        if (!empty($additionalOptions)) {
            $options = array_merge($options, $additionalOptions);
        }

        return $options;
    }
}
