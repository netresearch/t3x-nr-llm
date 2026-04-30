<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Service\SetupWizard\DTO\SuggestedConfiguration;

/**
 * Response DTO for the Setup Wizard `generateAction` AJAX action.
 *
 * Wraps the list of `SuggestedConfiguration` items produced by
 * `ConfigurationGenerator::generate()`. The wizard frontend renders
 * each entry's `identifier`, `name`, `description`, `systemPrompt`,
 * `recommendedModelId`, `temperature`, `maxTokens`, and
 * `additionalSettings` — i.e. the full
 * `SuggestedConfiguration::toArray()` shape.
 *
 * @internal
 */
final readonly class GeneratedConfigurationsResponse implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $configurations Configurations as produced by `SuggestedConfiguration::toArray()`
     */
    public function __construct(
        public array $configurations,
        public bool $success = true,
    ) {}

    /**
     * @param list<SuggestedConfiguration> $configurations
     */
    public static function fromSuggestedConfigurations(array $configurations): self
    {
        return new self(
            configurations: array_values(array_map(
                static fn(SuggestedConfiguration $c): array => $c->toArray(),
                $configurations,
            )),
        );
    }

    /**
     * @return array{success: bool, configurations: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'success'        => $this->success,
            'configurations' => $this->configurations,
        ];
    }
}
