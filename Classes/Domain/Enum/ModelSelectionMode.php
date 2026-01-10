<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Enum representing model selection modes.
 *
 * Defines how a model is selected for a task (fixed model or criteria-based).
 */
enum ModelSelectionMode: string
{
    case FIXED = 'fixed';
    case CRITERIA = 'criteria';

    /**
     * Get all selection mode values as an array.
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
     * Check if a given string is a valid selection mode.
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
     * Get a human-readable description for this selection mode.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::FIXED => 'Use a specific, pre-configured model',
            self::CRITERIA => 'Select model based on capabilities and requirements',
        };
    }
}
