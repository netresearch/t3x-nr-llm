<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\Model\Model;

/**
 * Resolves the reasoning profile of a model record (ADR-274).
 *
 * Each field is resolved on its own, in this order: the operator override on
 * the record, the bundled measured table ({@see OpenAiModelProfiles::measured()}),
 * the discovered profile stored on the record, the family default in code
 * ({@see OpenAiModelProfiles::familyDefault()}, which ends in the unknown
 * profile). The tool route skips the discovered profile: no discovery source
 * states it for OpenAI's own endpoints.
 *
 * Static like {@see OpenAiModelProfiles}: it reads only the record and the
 * table, and its consumers include static code ({@see \Netresearch\NrLlm\Service\OutputCeiling}).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ModelRecordProfileResolver
{
    /**
     * The adapter types whose models are described by this profile: the
     * ones the configuration form consults it for. A model of any other
     * adapter type has no reasoning profile to show.
     */
    public const ADAPTER_TYPES = ['openai', 'azure_openai', 'openrouter', 'groq', 'custom'];

    public static function resolve(Model $model): ResolvedModelProfile
    {
        $modelId  = $model->getModelId();
        $measured = OpenAiModelProfiles::measured($modelId);
        $default  = OpenAiModelProfiles::familyDefault($modelId);

        $discoveredSource = self::discoveredSource($model);

        [$isReasoning, $reasoningSource] = self::reasoning($model, $measured, $default, $discoveredSource);
        [$efforts, $effortsSource]       = self::efforts($model, $measured, $default, $discoveredSource);
        [$toolsOnChat, $toolRouteSource] = self::toolRoute($model, $measured, $default);

        $defaultEffort = $measured?->defaultEffort;

        return new ResolvedModelProfile(
            profile: new OpenAiModelProfile(
                family: $measured->family ?? $default->family,
                isReasoningModel: $isReasoning,
                supportedEfforts: $efforts,
                defaultEffort: in_array($defaultEffort, $efforts, true) ? $defaultEffort : null,
                toolsOnChatCompletions: $toolsOnChat,
            ),
            reasoningSource: $reasoningSource,
            effortsSource: $effortsSource,
            toolRouteSource: $toolRouteSource,
        );
    }

    /**
     * The resolved profile per model uid, for the models whose adapter type
     * consumes it — what the model list shows (ADR-274).
     *
     * @param iterable<mixed> $models
     *
     * @return array<int, ResolvedModelProfile>
     */
    public static function resolveForList(iterable $models): array
    {
        $resolved = [];
        foreach ($models as $model) {
            $uid = $model instanceof Model ? $model->getUid() : null;
            if ($uid !== null && $model instanceof Model && self::appliesTo($model)) {
                $resolved[$uid] = self::resolve($model);
            }
        }

        return $resolved;
    }

    /**
     * Whether the model's adapter type consumes this profile at all.
     */
    public static function appliesTo(Model $model): bool
    {
        return in_array($model->getProvider()?->getAdapterType(), self::ADAPTER_TYPES, true);
    }

    /**
     * @return array{bool, ProfileFieldSource}
     */
    private static function reasoning(
        Model $model,
        ?OpenAiModelProfile $measured,
        OpenAiModelProfile $default,
        ?ProfileFieldSource $discoveredSource,
    ): array {
        $override = $model->getReasoningOverrideFlag();
        if ($override !== null) {
            return [$override, ProfileFieldSource::Operator];
        }

        if ($measured instanceof OpenAiModelProfile) {
            return [$measured->isReasoningModel, ProfileFieldSource::Table];
        }

        $discovered = $model->getReasoningDiscoveredFlag();
        if ($discovered !== null && $discoveredSource instanceof ProfileFieldSource) {
            return [$discovered, $discoveredSource];
        }

        return [$default->isReasoningModel, ProfileFieldSource::FamilyDefault];
    }

    /**
     * A discovered scale counts only from the provider's own model list; the
     * entity already refuses to hand one out for any other source.
     *
     * @return array{list<ReasoningEffort>, ProfileFieldSource}
     */
    private static function efforts(
        Model $model,
        ?OpenAiModelProfile $measured,
        OpenAiModelProfile $default,
        ?ProfileFieldSource $discoveredSource,
    ): array {
        $override = $model->getReasoningEffortsOverrideList();
        if ($override !== []) {
            return [$override, ProfileFieldSource::Operator];
        }

        if ($measured instanceof OpenAiModelProfile) {
            return [$measured->supportedEfforts, ProfileFieldSource::Table];
        }

        $discovered = $model->getReasoningEffortsDiscoveredList();
        if ($discovered !== [] && $discoveredSource === ProfileFieldSource::Discovery) {
            return [$discovered, ProfileFieldSource::Discovery];
        }

        return [$default->supportedEfforts, ProfileFieldSource::FamilyDefault];
    }

    /**
     * @return array{bool, ProfileFieldSource}
     */
    private static function toolRoute(Model $model, ?OpenAiModelProfile $measured, OpenAiModelProfile $default): array
    {
        $override = $model->getToolsOnChatCompletionsOverride();
        if ($override !== null) {
            return [$override, ProfileFieldSource::Operator];
        }

        if ($measured instanceof OpenAiModelProfile) {
            return [$measured->toolsOnChatCompletions, ProfileFieldSource::Table];
        }

        return [$default->toolsOnChatCompletions, ProfileFieldSource::FamilyDefault];
    }

    /**
     * The source of the stored discovered profile, or null when the record
     * holds none: no recorded date, or a source that cannot state reasoning.
     */
    private static function discoveredSource(Model $model): ?ProfileFieldSource
    {
        if (!$model->getReasoningConfirmedDate() instanceof DateTimeImmutable) {
            return null;
        }

        return match ($model->getReasoningSourceEnum()) {
            CapabilitySource::Discovery         => ProfileFieldSource::Discovery,
            CapabilitySource::ThirdPartyCatalog => ProfileFieldSource::ThirdPartyCatalog,
            default                             => null,
        };
    }
}
