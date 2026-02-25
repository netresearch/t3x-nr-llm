<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Model\Model;

/**
 * Response DTO for model list AJAX responses.
 *
 * @internal
 */
final readonly class ModelListResponse implements JsonSerializable
{
    /**
     * @param list<ModelListItemResponse> $models
     */
    public function __construct(
        public bool $success,
        public array $models,
    ) {}

    /**
     * Create from an iterable of Model entities.
     *
     * @param iterable<Model> $models
     */
    public static function fromModels(iterable $models): self
    {
        $items = [];
        foreach ($models as $model) {
            $items[] = ModelListItemResponse::fromModel($model);
        }

        return new self(
            success: true,
            models: $items,
        );
    }

    /**
     * @return array{success: bool, models: list<array{uid: int, identifier: string, name: string, modelId: string, isDefault: bool}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'models' => array_map(
                static fn(ModelListItemResponse $item): array => $item->jsonSerialize(),
                $this->models,
            ),
        ];
    }
}
