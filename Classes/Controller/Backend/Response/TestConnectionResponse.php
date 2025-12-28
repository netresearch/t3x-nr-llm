<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for provider connection test AJAX action.
 *
 * @internal
 */
final readonly class TestConnectionResponse implements JsonSerializable
{
    /**
     * @param list<string> $models List of available model IDs
     */
    public function __construct(
        public bool $success,
        public string $message,
        public array $models = [],
    ) {}

    /**
     * Create from test result array.
     *
     * @param array{success: bool, message: string, models?: array<string, string>} $result
     */
    public static function fromResult(array $result): self
    {
        // Models come as id => name map, extract just the IDs
        $models = isset($result['models']) && is_array($result['models'])
            ? array_keys($result['models'])
            : [];

        return new self(
            success: $result['success'],
            message: $result['message'],
            models: $models,
        );
    }

    /**
     * @return array{success: bool, message: string, models: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'models' => $this->models,
        ];
    }
}
