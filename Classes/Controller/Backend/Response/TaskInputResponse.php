<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for `TaskController::refreshInputAction()`.
 *
 * Carries the resolved input data plus a couple of flags the JS
 * frontend uses to decide whether to render an empty-state hint.
 *
 * @internal
 */
final readonly class TaskInputResponse implements JsonSerializable
{
    public function __construct(
        public string $inputData,
        public string $inputType,
        public bool $isEmpty,
        public bool $success = true,
    ) {}

    /**
     * @return array{success: bool, inputData: string, inputType: string, isEmpty: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'success'   => $this->success,
            'inputData' => $this->inputData,
            'inputType' => $this->inputType,
            'isEmpty'   => $this->isEmpty,
        ];
    }
}
