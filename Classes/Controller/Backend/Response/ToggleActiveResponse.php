<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for toggle active AJAX actions.
 *
 * @internal
 */
final readonly class ToggleActiveResponse implements JsonSerializable
{
    public function __construct(
        public bool $success,
        public bool $isActive,
    ) {}

    /**
     * @return array{success: bool, isActive: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'isActive' => $this->isActive,
        ];
    }
}
