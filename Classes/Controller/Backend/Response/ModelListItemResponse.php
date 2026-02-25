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
 * Response DTO for individual model items in list responses.
 *
 * @internal
 */
final readonly class ModelListItemResponse implements JsonSerializable
{
    public function __construct(
        public int $uid,
        public string $identifier,
        public string $name,
        public string $modelId,
        public bool $isDefault,
    ) {}

    /**
     * Create from domain Model entity.
     */
    public static function fromModel(Model $model): self
    {
        return new self(
            uid: $model->getUid() ?? 0,
            identifier: $model->getIdentifier(),
            name: $model->getName(),
            modelId: $model->getModelId(),
            isDefault: $model->isDefault(),
        );
    }

    /**
     * @return array{uid: int, identifier: string, name: string, modelId: string, isDefault: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'uid' => $this->uid,
            'identifier' => $this->identifier,
            'name' => $this->name,
            'modelId' => $this->modelId,
            'isDefault' => $this->isDefault,
        ];
    }
}
