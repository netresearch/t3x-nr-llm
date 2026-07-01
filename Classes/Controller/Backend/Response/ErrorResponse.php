<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;

/**
 * Response DTO for error AJAX responses.
 *
 * @internal
 */
final readonly class ErrorResponse implements JsonSerializable
{
    use ErrorMessageSanitizerTrait;

    public string $error;

    public function __construct(
        string $error,
        public bool $success = false,
    ) {
        // Defence at the response boundary: controllers pass raw
        // `$e->getMessage()` here, so redact credential-bearing URL params
        // (e.g. a provider's `?key=…`) before the message reaches the client.
        $this->error = $this->sanitizeErrorMessage($error);
    }

    /**
     * @return array{success: bool, error: string}
     */
    public function jsonSerialize(): array
    {
        // `success` first matches the natural read order and the
        // pre-typed JSON literals (`['success' => false, 'error' => ...]`)
        // every controller used before slice 13d.
        return [
            'success' => $this->success,
            'error'   => $this->error,
        ];
    }
}
