<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Request DTO for fetching database records AJAX endpoint.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class FetchRecordsRequest
{
    private const int MAX_LIMIT = 200;
    private const int DEFAULT_LIMIT = 50;

    public function __construct(
        public string $table,
        public int $limit,
        public string $labelField,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $data */
        $data = is_array($body) ? $body : [];

        $rawLimit = self::extractInt($data, 'limit', self::DEFAULT_LIMIT);

        return new self(
            table: self::extractString($data, 'table'),
            limit: min($rawLimit, self::MAX_LIMIT),
            labelField: self::extractString($data, 'labelField'),
        );
    }

    public function isValid(): bool
    {
        return $this->table !== '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractString(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractInt(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }
}
