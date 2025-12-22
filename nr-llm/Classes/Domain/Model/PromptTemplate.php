<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Domain model for prompt templates
 *
 * Represents a reusable prompt template with versioning,
 * configuration, and performance tracking.
 */
class PromptTemplate
{
    private ?int $uid = null;
    private int $pid = 0;
    private string $identifier = '';
    private string $title = '';
    private ?string $description = null;
    private string $feature = '';
    private ?string $systemPrompt = null;
    private ?string $userPromptTemplate = null;
    private int $version = 1;
    private int $parentUid = 0;
    private bool $isActive = true;
    private bool $isDefault = false;
    private ?string $provider = null;
    private ?string $model = null;
    private float $temperature = 0.7;
    private int $maxTokens = 1000;
    private float $topP = 1.0;
    private array $variables = [];
    private ?string $exampleOutput = null;
    private array $tags = [];
    private int $usageCount = 0;
    private int $avgResponseTime = 0;
    private int $avgTokensUsed = 0;
    private float $qualityScore = 0.0;
    private int $tstamp = 0;
    private int $crdate = 0;

    // Getters
    public function getUid(): ?int
    {
        return $this->uid;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

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

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function getExampleOutput(): ?string
    {
        return $this->exampleOutput;
    }

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
        $this->uid = $uid;
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

    public function setVariables(array $variables): void
    {
        $this->variables = $variables;
    }

    public function setExampleOutput(?string $exampleOutput): void
    {
        $this->exampleOutput = $exampleOutput;
    }

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
     * Get required variables from template
     *
     * Extracts all {{variable}} placeholders from prompts
     *
     * @return array
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
     * Check if template has performance data
     *
     * @return bool
     */
    public function hasPerformanceData(): bool
    {
        return $this->usageCount > 0;
    }
}
