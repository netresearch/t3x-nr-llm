<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for error AJAX responses.
 *
 * @internal
 */
final readonly class ErrorResponse implements JsonSerializable
{
    public function __construct(
        public string $error,
        public bool $success = false,
    ) {}

    /**
     * @return array{error: string, success: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'error' => $this->error,
            'success' => $this->success,
        ];
    }
}
