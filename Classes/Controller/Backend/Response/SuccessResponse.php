<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for simple success AJAX actions.
 *
 * @internal
 */
final readonly class SuccessResponse implements JsonSerializable
{
    public function __construct(
        public bool $success = true,
    ) {}

    /**
     * @return array{success: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
        ];
    }
}
