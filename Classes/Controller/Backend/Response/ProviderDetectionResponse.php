<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;

/**
 * Response DTO for the Setup Wizard `detectAction` AJAX action.
 *
 * Wraps a `DetectedProvider` result for the wizard frontend, which
 * reads `result.provider.suggestedName`, `result.provider.adapterType`,
 * `result.provider.endpoint`, and `result.provider.confidence` from
 * the JSON body.
 *
 * @internal
 */
final readonly class ProviderDetectionResponse implements JsonSerializable
{
    /**
     * @param array<string, mixed> $provider Provider metadata as produced by `DetectedProvider::toArray()`
     */
    public function __construct(
        public array $provider,
        public bool $success = true,
    ) {}

    public static function fromDetectedProvider(DetectedProvider $detected): self
    {
        return new self(provider: $detected->toArray());
    }

    /**
     * @return array{success: bool, provider: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'success'  => $this->success,
            'provider' => $this->provider,
        ];
    }
}
