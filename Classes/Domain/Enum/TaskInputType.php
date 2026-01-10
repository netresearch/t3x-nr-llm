<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Enum representing task input types.
 *
 * Defines the source of input data for a task (manual entry, system logs, etc.).
 */
enum TaskInputType: string
{
    case MANUAL = 'manual';
    case SYSLOG = 'syslog';
    case DEPRECATION_LOG = 'deprecation_log';
    case TABLE = 'table';
    case FILE = 'file';

    /**
     * Get all input type values as an array.
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
     * Check if a given string is a valid input type.
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
     * Get a human-readable label for this input type.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual Input',
            self::SYSLOG => 'System Log',
            self::DEPRECATION_LOG => 'Deprecation Log',
            self::TABLE => 'Database Table',
            self::FILE => 'File',
        };
    }
}
