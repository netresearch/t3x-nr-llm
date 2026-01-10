<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Domain\Enum\TaskCategory;
use Netresearch\NrLlm\Domain\Enum\TaskInputType;
use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
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
    /**
     * Input types.
     *
     * @deprecated Use TaskInputType enum instead
     */
    public const INPUT_MANUAL = 'manual';
    /** @deprecated Use TaskInputType enum instead */
    public const INPUT_SYSLOG = 'syslog';
    /** @deprecated Use TaskInputType enum instead */
    public const INPUT_DEPRECATION_LOG = 'deprecation_log';
    /** @deprecated Use TaskInputType enum instead */
    public const INPUT_TABLE = 'table';
    /** @deprecated Use TaskInputType enum instead */
    public const INPUT_FILE = 'file';

    /**
     * Output formats.
     *
     * @deprecated Use TaskOutputFormat enum instead
     */
    public const OUTPUT_MARKDOWN = 'markdown';
    /** @deprecated Use TaskOutputFormat enum instead */
    public const OUTPUT_JSON = 'json';
    /** @deprecated Use TaskOutputFormat enum instead */
    public const OUTPUT_PLAIN = 'plain';
    /** @deprecated Use TaskOutputFormat enum instead */
    public const OUTPUT_HTML = 'html';

    /**
     * Categories.
     *
     * @deprecated Use TaskCategory enum instead
     */
    public const CATEGORY_LOG_ANALYSIS = 'log_analysis';
    /** @deprecated Use TaskCategory enum instead */
    public const CATEGORY_CONTENT = 'content';
    /** @deprecated Use TaskCategory enum instead */
    public const CATEGORY_SYSTEM = 'system';
    /** @deprecated Use TaskCategory enum instead */
    public const CATEGORY_DEVELOPER = 'developer';
    /** @deprecated Use TaskCategory enum instead */
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

    /**
     * Get category as enum.
     */
    public function getCategoryEnum(): ?TaskCategory
    {
        return TaskCategory::tryFrom($this->category);
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

    /**
     * Get input type as enum.
     */
    public function getInputTypeEnum(): ?TaskInputType
    {
        return TaskInputType::tryFrom($this->inputType);
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

    /**
     * Get output format as enum.
     */
    public function getOutputFormatEnum(): ?TaskOutputFormat
    {
        return TaskOutputFormat::tryFrom($this->outputFormat);
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

    public function setCategory(string|TaskCategory $category): void
    {
        $this->category = $category instanceof TaskCategory ? $category->value : $category;
    }

    public function setConfiguration(?LlmConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function setPromptTemplate(string $promptTemplate): void
    {
        $this->promptTemplate = $promptTemplate;
    }

    public function setInputType(string|TaskInputType $inputType): void
    {
        $this->inputType = $inputType instanceof TaskInputType ? $inputType->value : $inputType;
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

    public function setOutputFormat(string|TaskOutputFormat $outputFormat): void
    {
        $this->outputFormat = $outputFormat instanceof TaskOutputFormat ? $outputFormat->value : $outputFormat;
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
        return $this->inputType === TaskInputType::MANUAL->value;
    }

    /**
     * Get available input types.
     *
     * @return array<string, string>
     */
    public static function getInputTypes(): array
    {
        return [
            TaskInputType::MANUAL->value => 'Manual Input',
            TaskInputType::SYSLOG->value => 'System Log (sys_log)',
            TaskInputType::DEPRECATION_LOG->value => 'Deprecation Log',
            TaskInputType::TABLE->value => 'Database Table',
            TaskInputType::FILE->value => 'File',
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
            TaskOutputFormat::MARKDOWN->value => 'Markdown',
            TaskOutputFormat::JSON->value => 'JSON',
            TaskOutputFormat::PLAIN->value => 'Plain Text',
            TaskOutputFormat::HTML->value => 'HTML',
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
            TaskCategory::LOG_ANALYSIS->value => 'Log Analysis',
            TaskCategory::CONTENT->value => 'Content Operations',
            TaskCategory::SYSTEM->value => 'System Health',
            TaskCategory::DEVELOPER->value => 'Developer Assistance',
            TaskCategory::GENERAL->value => 'General',
        ];
    }
}
