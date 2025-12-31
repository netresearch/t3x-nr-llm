<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

use Netresearch\NrLlm\Domain\Model\Task;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Data Transfer Object for Task form input.
 *
 * Encapsulates validated form data from HTTP requests.
 * This separates HTTP input handling from domain model creation.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class TaskFormInput
{
    /**
     * @param array<string, mixed> $inputSourceConfig
     */
    public function __construct(
        public int $uid,
        public string $identifier,
        public string $name,
        public string $description,
        public string $category,
        public int $configurationUid,
        public string $promptTemplate,
        public string $inputType,
        public array $inputSourceConfig,
        public string $outputFormat,
        public bool $isActive,
        public bool $isSystem,
        public int $sorting,
    ) {}

    /**
     * Check if this is an update (existing task) or create (new task).
     */
    public function isUpdate(): bool
    {
        return $this->uid > 0;
    }

    /**
     * Create from PSR-7 request.
     *
     * Extracts the 'task' sub-array from the request body.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();
        $taskData = is_array($body) && is_array($body['task'] ?? null) ? $body['task'] : [];
        /** @var array<string, mixed> $data */
        $data = $taskData;

        return self::fromRequestData($data);
    }

    /**
     * Create from HTTP request body.
     *
     * @param array<string, mixed> $data Raw form data from request
     */
    public static function fromRequestData(array $data): self
    {
        // Parse input source configuration
        $inputSourceString = self::extractString($data, 'inputSource');
        $inputSourceConfig = [];
        if ($inputSourceString !== '') {
            $decoded = json_decode($inputSourceString, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $inputSourceConfig = $decoded;
            }
        }

        return new self(
            uid: self::extractInt($data, 'uid'),
            identifier: self::extractString($data, 'identifier'),
            name: self::extractString($data, 'name'),
            description: self::extractString($data, 'description'),
            category: self::extractString($data, 'category', Task::CATEGORY_GENERAL),
            configurationUid: self::extractInt($data, 'configurationUid'),
            promptTemplate: self::extractString($data, 'promptTemplate'),
            inputType: self::extractString($data, 'inputType', Task::INPUT_MANUAL),
            inputSourceConfig: $inputSourceConfig,
            outputFormat: self::extractString($data, 'outputFormat', Task::OUTPUT_MARKDOWN),
            isActive: self::extractBool($data, 'isActive', true),
            isSystem: self::extractBool($data, 'isSystem', false),
            sorting: self::extractInt($data, 'sorting'),
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
