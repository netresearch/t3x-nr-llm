<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;

/**
 * Response DTO for the Setup Wizard `discoverAction` AJAX action.
 *
 * Wraps the list of `DiscoveredModel` results from
 * `ModelDiscoveryInterface::discover()`. The wizard frontend renders
 * each `models[i]` entry's `modelId`, `name`, `description`,
 * `capabilities[]`, `contextLength`, `maxOutputTokens`, and
 * `recommended` fields — i.e. the full `DiscoveredModel::toArray()`
 * shape.
 *
 * @internal
 */
final readonly class DiscoveredModelsResponse implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $models Models as produced by `DiscoveredModel::toArray()`
     */
    public function __construct(
        public array $models,
        public bool $success = true,
    ) {}

    /**
     * @param list<DiscoveredModel> $models
     */
    public static function fromDiscoveredModels(array $models): self
    {
        return new self(
            models: array_values(array_map(
                static fn(DiscoveredModel $m): array => $m->toArray(),
                $models,
            )),
        );
    }

    /**
     * @return array{success: bool, models: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'models'  => $this->models,
        ];
    }
}
