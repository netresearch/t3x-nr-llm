<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Domain model for one-shot prompt tasks.
 *
 * Tasks are predefined prompt templates for common operations like
 * analyzing logs, generating content, or performing quick AI-assisted tasks.
 *
 * IMPORTANT: Tasks are simple one-shot prompts with limited capabilities.
 * They are NOT AI agents and cannot perform multi-step reasoning, use tools,
 * or maintain conversation context. They are primarily for demonstration
 * and simple utility purposes.
 */
class Task extends AbstractEntity
{
    // Input types
    public const INPUT_MANUAL = 'manual';
    public const INPUT_SYSLOG = 'syslog';
    public const INPUT_DEPRECATION_LOG = 'deprecation_log';
    public const INPUT_TABLE = 'table';
    public const INPUT_FILE = 'file';

    // Output formats
    public const OUTPUT_MARKDOWN = 'markdown';
    public const OUTPUT_JSON = 'json';
    public const OUTPUT_PLAIN = 'plain';
    public const OUTPUT_HTML = 'html';

    // Categories
    public const CATEGORY_LOG_ANALYSIS = 'log_analysis';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_DEVELOPER = 'developer';
    public const CATEGORY_GENERAL = 'general';

    protected string $identifier = '';
    protected string $name = '';
    protected string $description = '';
    protected string $category = self::CATEGORY_GENERAL;
    protected ?LlmConfiguration $configuration = null;
    protected string $promptTemplate = '';
    protected string $inputType = 'manual';
    protected string $inputSource = '';
    protected string $outputFormat = self::OUTPUT_MARKDOWN;
    protected bool $isActive = true;
    protected bool $isSystem = false;
    protected int $sorting = 0;
    protected int $tstamp = 0;
    protected int $crdate = 0;

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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getConfiguration(): ?LlmConfiguration
    {
        return $this->configuration;
    }

    public function getPromptTemplate(): string
    {
        return $this->promptTemplate;
    }

    public function getInputType(): string
    {
        return $this->inputType;
    }

    public function getInputSource(): string
    {
        return $this->inputSource;
    }

    /**
     * Get parsed input source configuration.
     *
     * @return array<string, mixed>
     */
    public function getInputSourceArray(): array
    {
        if ($this->inputSource === '') {
            return [];
        }
        $decoded = json_decode($this->inputSource, true);
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function getOutputFormat(): string
    {
        return $this->outputFormat;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getIsSystem(): bool
    {
        return $this->isSystem;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
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

    public function setCategory(string $category): void
    {
        $this->category = $category;
    }

    public function setConfiguration(?LlmConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function setPromptTemplate(string $promptTemplate): void
    {
        $this->promptTemplate = $promptTemplate;
    }

    public function setInputType(string $inputType): void
    {
        $this->inputType = $inputType;
    }

    public function setInputSource(string $inputSource): void
    {
        $this->inputSource = $inputSource;
    }

    /**
     * Set input source from array.
     *
     * @param array<string, mixed> $inputSource
     */
    public function setInputSourceArray(array $inputSource): void
    {
        $this->inputSource = json_encode($inputSource, JSON_THROW_ON_ERROR);
    }

    public function setOutputFormat(string $outputFormat): void
    {
        $this->outputFormat = $outputFormat;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setIsSystem(bool $isSystem): void
    {
        $this->isSystem = $isSystem;
    }

    public function setSorting(int $sorting): void
    {
        $this->sorting = $sorting;
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Build the final prompt by replacing placeholders.
     *
     * @param array<string, string> $variables Variables to replace (e.g., ['input' => '...'])
     */
    public function buildPrompt(array $variables = []): string
    {
        $prompt = $this->promptTemplate;

        foreach ($variables as $key => $value) {
            $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
        }

        return $prompt;
    }

    /**
     * Check if task requires manual input.
     */
    public function requiresManualInput(): bool
    {
        return $this->inputType === self::INPUT_MANUAL;
    }

    /**
     * Get available input types.
     *
     * @return array<string, string>
     */
    public static function getInputTypes(): array
    {
        return [
            self::INPUT_MANUAL => 'Manual Input',
            self::INPUT_SYSLOG => 'System Log (sys_log)',
            self::INPUT_DEPRECATION_LOG => 'Deprecation Log',
            self::INPUT_TABLE => 'Database Table',
            self::INPUT_FILE => 'File',
        ];
    }

    /**
     * Get available output formats.
     *
     * @return array<string, string>
     */
    public static function getOutputFormats(): array
    {
        return [
            self::OUTPUT_MARKDOWN => 'Markdown',
            self::OUTPUT_JSON => 'JSON',
            self::OUTPUT_PLAIN => 'Plain Text',
            self::OUTPUT_HTML => 'HTML',
        ];
    }

    /**
     * Get available categories.
     *
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_LOG_ANALYSIS => 'Log Analysis',
            self::CATEGORY_CONTENT => 'Content Operations',
            self::CATEGORY_SYSTEM => 'System Health',
            self::CATEGORY_DEVELOPER => 'Developer Assistance',
            self::CATEGORY_GENERAL => 'General',
        ];
    }
}
