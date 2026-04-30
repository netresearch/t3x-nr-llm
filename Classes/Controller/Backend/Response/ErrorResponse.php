<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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
