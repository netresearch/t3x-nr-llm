<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;

/**
 * Data Transfer Object for LlmConfiguration form input.
 *
 * Encapsulates validated form data from HTTP requests.
 * This separates HTTP input handling from domain model creation.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class ConfigurationFormInput
{
    /**
     * @param array<string, mixed> $modelSelectionCriteria
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public int $modelUid,
        public string $modelSelectionMode,
        public array $modelSelectionCriteria,
        public string $systemPrompt,
        public float $temperature,
        public int $maxTokens,
        public float $topP,
        public float $frequencyPenalty,
        public float $presencePenalty,
        public int $timeout,
        public int $maxRequestsPerDay,
        public int $maxTokensPerDay,
        public float $maxCostPerDay,
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
        // Parse model selection criteria
        $criteriaString = self::extractString($data, 'modelSelectionCriteria');
        $criteria = [];
        if ($criteriaString !== '') {
            $decoded = json_decode($criteriaString, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $criteria = $decoded;
            }
        }

        return new self(
            identifier: self::extractString($data, 'identifier'),
            name: self::extractString($data, 'name'),
            description: self::extractString($data, 'description'),
            modelUid: self::extractInt($data, 'modelUid'),
            modelSelectionMode: self::extractString($data, 'modelSelectionMode', LlmConfiguration::SELECTION_MODE_FIXED),
            modelSelectionCriteria: $criteria,
            systemPrompt: self::extractString($data, 'systemPrompt'),
            temperature: self::extractFloat($data, 'temperature', 0.7),
            maxTokens: self::extractInt($data, 'maxTokens', 1000),
            topP: self::extractFloat($data, 'topP', 1.0),
            frequencyPenalty: self::extractFloat($data, 'frequencyPenalty', 0.0),
            presencePenalty: self::extractFloat($data, 'presencePenalty', 0.0),
            timeout: self::extractInt($data, 'timeout'),
            maxRequestsPerDay: self::extractInt($data, 'maxRequestsPerDay'),
            maxTokensPerDay: self::extractInt($data, 'maxTokensPerDay'),
            maxCostPerDay: self::extractFloat($data, 'maxCostPerDay'),
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
    private static function extractFloat(array $data, string $key, float $default = 0.0): float
    {
        $value = $data[$key] ?? $default;
        return is_numeric($value) ? (float)$value : $default;
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
