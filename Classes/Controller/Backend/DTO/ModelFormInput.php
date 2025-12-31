<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

/**
 * Data Transfer Object for Model form input.
 *
 * Encapsulates validated form data from HTTP requests.
 * This separates HTTP input handling from domain model creation.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class ModelFormInput
{
    /**
     * @param string[] $capabilities
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public int $providerUid,
        public string $modelId,
        public int $contextLength,
        public int $maxOutputTokens,
        public int $defaultTimeout,
        public array $capabilities,
        public int $costInput,
        public int $costOutput,
        public bool $isActive,
        public bool $isDefault,
    ) {}

    /**
     * Create from HTTP request body.
     *
     * @param array<string, mixed> $data Raw form data from request
     */
    public static function fromRequestData(array $data): self
    {
        // Extract and validate capabilities
        $capabilities = [];
        if (isset($data['capabilities'])) {
            if (is_array($data['capabilities'])) {
                $capabilities = array_values(array_filter($data['capabilities'], is_string(...)));
            } elseif (is_string($data['capabilities']) && $data['capabilities'] !== '') {
                $capabilities = array_map(trim(...), explode(',', $data['capabilities']));
            }
        }

        return new self(
            identifier: self::extractString($data, 'identifier'),
            name: self::extractString($data, 'name'),
            description: self::extractString($data, 'description'),
            providerUid: self::extractInt($data, 'providerUid'),
            modelId: self::extractString($data, 'modelId'),
            contextLength: self::extractInt($data, 'contextLength'),
            maxOutputTokens: self::extractInt($data, 'maxOutputTokens'),
            defaultTimeout: self::extractInt($data, 'defaultTimeout', 120),
            capabilities: $capabilities,
            costInput: self::extractInt($data, 'costInput'),
            costOutput: self::extractInt($data, 'costOutput'),
            isActive: self::extractBool($data, 'isActive', true),
            isDefault: self::extractBool($data, 'isDefault', false),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractString(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractInt(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractBool(array $data, string $key, bool $default = false): bool
    {
        if (!isset($data[$key])) {
            return $default;
        }
        return (bool)$data[$key];
    }
}
