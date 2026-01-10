<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Enum representing task categories.
 *
 * Defines the category/type of a task for organization and filtering.
 */
enum TaskCategory: string
{
    case LOG_ANALYSIS = 'log_analysis';
    case CONTENT = 'content';
    case SYSTEM = 'system';
    case DEVELOPER = 'developer';
    case GENERAL = 'general';

    /**
     * Get all category values as an array.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn(self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * Check if a given string is a valid category.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Try to create from string, returns null if invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * Get a human-readable label for this category.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::LOG_ANALYSIS => 'Log Analysis',
            self::CONTENT => 'Content',
            self::SYSTEM => 'System',
            self::DEVELOPER => 'Developer',
            self::GENERAL => 'General',
        };
    }

    /**
     * Get the icon identifier for this category.
     */
    public function getIconIdentifier(): string
    {
        return match ($this) {
            self::LOG_ANALYSIS => 'actions-document-info',
            self::CONTENT => 'actions-document-edit',
            self::SYSTEM => 'actions-cog',
            self::DEVELOPER => 'actions-code',
            self::GENERAL => 'actions-rocket',
        };
    }
}
