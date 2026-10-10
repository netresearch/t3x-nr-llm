<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;

/**
 * Adds evidence only after the configured adapter terminal succeeds (ADR-220). @internal.
 */
final class ServingGeneratorMetadata
{
    /**
     * @param array<string,mixed> $callOptions
     */
    public static function attach(
        CompletionResponse $response,
        ?Model $model,
        array $callOptions,
    ): CompletionResponse {
        $record = GeneratorProvenance::fromArray(
            [
                'version' => 1,
                'providerIdentifier' => $model?->getProvider()?->getIdentifier(),
                'modelId' => $callOptions['model'] ?? null,
                'reportedModelId' => $response->model,
            ],
        );
        $metadata = $response->metadata;
        if (!$record instanceof GeneratorProvenance && !array_key_exists(GeneratorProvenance::METADATA_KEY, $metadata ?? [])) {
            return $response;
        }

        $metadata ??= [];
        unset($metadata[GeneratorProvenance::METADATA_KEY]);
        if ($record instanceof GeneratorProvenance) {
            $metadata[GeneratorProvenance::METADATA_KEY] = $record->toArray();
        }

        return new CompletionResponse(
            $response->content,
            $response->model,
            $response->usage,
            $response->finishReason,
            $response->provider,
            $response->toolCalls,
            $metadata,
            $response->thinking,
        );
    }
}
