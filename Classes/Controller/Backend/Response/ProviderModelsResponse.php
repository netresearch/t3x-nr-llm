<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for provider models AJAX action.
 *
 * Used when fetching available models from a provider adapter.
 *
 * @internal
 */
final readonly class ProviderModelsResponse implements JsonSerializable
{
    /**
     * @param array<string, string> $models Map of model ID to display name
     */
    public function __construct(
        public bool $success,
        public array $models,
        public string $defaultModel,
    ) {}

    /**
     * @return array{success: bool, models: array<string, string>, defaultModel: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'models' => $this->models,
            'defaultModel' => $this->defaultModel,
        ];
    }
}
