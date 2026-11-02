<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\Model\AdapterType;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfiles;

use function in_array;
use function max;

/**
 * The most output tokens one call on a configuration can use, for a cost
 * estimate (ADR-241). The stored limit, or the floor a reasoning model gets.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class OutputCeiling
{
    /** The adapters served by \Netresearch\NrLlm\Provider\OpenAiProvider that can reach a reasoning model. */
    private const FLOORED_ADAPTERS = [AdapterType::OpenAI, AdapterType::AzureOpenAI, AdapterType::Custom];

    /**
     * Null without a limit: `maxTokens` 0 sends none, which is unbounded.
     */
    public static function of(LlmConfiguration $configuration): ?int
    {
        $limit = $configuration->getMaxTokens();
        if ($limit <= 0) {
            return null;
        }

        $adapter = $configuration->getLlmModel()?->getProvider()?->getAdapterTypeEnum();
        $effort  = ReasoningEffort::tryFromOption($configuration->getOptionsArray()['reasoning_effort'] ?? null);

        return in_array($adapter, self::FLOORED_ADAPTERS, true)
            && OpenAiModelProfiles::forModel($configuration->getModelId())->isReasoningModel
            && $effort !== ReasoningEffort::None
            ? max($limit, OpenAiModelProfiles::REASONING_OUTPUT_FLOOR)
            : $limit;
    }
}
