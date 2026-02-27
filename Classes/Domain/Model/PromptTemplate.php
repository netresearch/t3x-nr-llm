<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Domain model for prompt templates.
 *
 * Represents a reusable prompt template with versioning,
 * configuration, and performance tracking.
 */
class PromptTemplate extends AbstractEntity
{
    protected string $identifier = '';
    protected string $title = '';
    protected ?string $description = null;
    protected string $feature = '';
    protected ?string $systemPrompt = null;
    protected ?string $userPromptTemplate = null;
    protected int $version = 1;
    protected int $parentUid = 0;
    protected bool $isActive = true;
    protected bool $isDefault = false;
    protected ?string $provider = null;
    protected ?string $model = null;
    protected float $temperature = 0.7;
    protected int $maxTokens = 1000;
    protected float $topP = 1.0;
    /** @var array<string, mixed> */
    protected array $variables = [];
    protected ?string $exampleOutput = null;
    /** @var array<int, string> */
    protected array $tags = [];
    protected int $usageCount = 0;
    protected int $avgResponseTime = 0;
    protected int $avgTokensUsed = 0;
    protected float $qualityScore = 0.0;
    protected int $tstamp = 0;
    protected int $crdate = 0;

    // Getters

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getFeature(): string
    {
        return $this->feature;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function getUserPromptTemplate(): ?string
    {
        return $this->userPromptTemplate;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getParentUid(): int
    {
        return $this->parentUid;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
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

    /**
     * @return array<string, mixed>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    public function getExampleOutput(): ?string
    {
        return $this->exampleOutput;
    }

    /**
     * @return array<int, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function getAvgResponseTime(): int
    {
        return $this->avgResponseTime;
    }

    public function getAvgTokensUsed(): int
    {
        return $this->avgTokensUsed;
    }

    public function getQualityScore(): float
    {
        return $this->qualityScore;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function getCrdate(): int
    {
        return $this->crdate;
    }

    // Setters
    public function setUid(?int $uid): void
    {
        // Parent class expects int<1, max>|null, so only set positive values
        if ($uid === null || $uid >= 1) {
            $this->uid = $uid;
        }
    }

    public function setPid(int $pid): void
    {
        $this->pid = $pid;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function setFeature(string $feature): void
    {
        $this->feature = $feature;
    }

    public function setSystemPrompt(?string $systemPrompt): void
    {
        $this->systemPrompt = $systemPrompt;
    }

    public function setUserPromptTemplate(?string $userPromptTemplate): void
    {
        $this->userPromptTemplate = $userPromptTemplate;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    public function setParentUid(int $parentUid): void
    {
        $this->parentUid = $parentUid;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setIsDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function setProvider(?string $provider): void
    {
        $this->provider = $provider;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
    }

    public function setTemperature(float $temperature): void
    {
        $this->temperature = $temperature;
    }

    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }

    public function setTopP(float $topP): void
    {
        $this->topP = $topP;
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function setVariables(array $variables): void
    {
        $this->variables = $variables;
    }

    public function setExampleOutput(?string $exampleOutput): void
    {
        $this->exampleOutput = $exampleOutput;
    }

    /**
     * @param array<int, string> $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    public function setUsageCount(int $usageCount): void
    {
        $this->usageCount = $usageCount;
    }

    public function setAvgResponseTime(int $avgResponseTime): void
    {
        $this->avgResponseTime = $avgResponseTime;
    }

    public function setAvgTokensUsed(int $avgTokensUsed): void
    {
        $this->avgTokensUsed = $avgTokensUsed;
    }

    public function setQualityScore(float $qualityScore): void
    {
        $this->qualityScore = $qualityScore;
    }

    public function setTstamp(int $tstamp): void
    {
        $this->tstamp = $tstamp;
    }

    public function setCrdate(int $crdate): void
    {
        $this->crdate = $crdate;
    }

    /**
     * Get required variables from template.
     *
     * Extracts all {{variable}} placeholders from prompts
     *
     * @return array<int, string>
     */
    public function getRequiredVariables(): array
    {
        $required = [];

        // Extract from system prompt
        if ($this->systemPrompt) {
            preg_match_all('/\{\{(\w+)\}\}/', $this->systemPrompt, $matches);
            $required = array_merge($required, $matches[1]);
        }

        // Extract from user prompt template
        if ($this->userPromptTemplate) {
            preg_match_all('/\{\{(\w+)\}\}/', $this->userPromptTemplate, $matches);
            $required = array_merge($required, $matches[1]);
        }

        // Remove duplicates and conditionals
        $required = array_unique($required);
        $required = array_filter($required, fn($v) => !in_array($v, ['if', 'else', 'each', 'this']));

        return array_values($required);
    }

    /**
     * Check if template has performance data.
     */
    public function hasPerformanceData(): bool
    {
        return $this->usageCount > 0;
    }
}
