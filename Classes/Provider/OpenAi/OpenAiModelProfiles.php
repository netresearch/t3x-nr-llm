<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;

/**
 * The model table behind {@see OpenAiModelProfile} (ADR-203).
 *
 * Every entry is a prefix rule, matched most specific first. The GPT-6 entries
 * state what the model pages state, read from
 * `developers.openai.com/api/docs/models/<id>` on 2026-09-23; where a page and
 * this table disagree, the page is right and this table is stale. The GPT-5
 * and o-series entry carries only what the replaced regex already decided.
 *
 * A model id that matches nothing gets {@see OpenAiModelProfile::unknown()}.
 * That is the answer for a compatible gateway's deployment name as well, and
 * it is why an unknown id keeps the transport it has always used.
 */
final class OpenAiModelProfiles
{
    /**
     * The GPT-6 scale as the Sol and Luna model pages state it: six values,
     * without `Minimal`. Astra's page lists the same six without `None`.
     *
     * @var list<ReasoningEffort>
     */
    private const GPT6_EFFORTS = [
        ReasoningEffort::None,
        ReasoningEffort::Low,
        ReasoningEffort::Medium,
        ReasoningEffort::High,
        ReasoningEffort::XHigh,
        ReasoningEffort::Max,
    ];

    /** @var list<ReasoningEffort> */
    private const GPT6_ASTRA_EFFORTS = [
        ReasoningEffort::Low,
        ReasoningEffort::Medium,
        ReasoningEffort::High,
        ReasoningEffort::XHigh,
        ReasoningEffort::Max,
    ];

    public static function forModel(string $model): OpenAiModelProfile
    {
        $model = strtolower(trim($model));

        // GPT-6 Astra: no `none` effort, and Chat Completions serves it no
        // function calling under any setting.
        if (str_starts_with($model, 'gpt-6-astra')) {
            return new OpenAiModelProfile(
                family: 'gpt-6-astra',
                isReasoningModel: true,
                supportedEfforts: self::GPT6_ASTRA_EFFORTS,
                defaultEffort: ReasoningEffort::Medium,
                toolsOnChatCompletions: false,
            );
        }

        // GPT-6 Sol and Luna: Chat Completions serves function calling only at
        // `none`, so this extension does not use it for tools.
        if (str_starts_with($model, 'gpt-6')) {
            return new OpenAiModelProfile(
                family: self::gpt6Family($model),
                isReasoningModel: true,
                supportedEfforts: self::GPT6_EFFORTS,
                defaultEffort: ReasoningEffort::Medium,
                toolsOnChatCompletions: false,
            );
        }

        // GPT-5.x and the o-series: reasoning models, and the two families the
        // replaced regex covered, so sampling parameters stay stripped. Their
        // tool calling on Chat Completions has never been reported as refused,
        // so the transport is left where it is.
        //
        // No effort scale, deliberately. The scales differ inside both
        // families — o1-mini and o1-preview take no `reasoning_effort` at all,
        // o1 and o3 stop at low/medium/high, `minimal` and `none` arrived with
        // particular GPT-5 releases — and none of it was read from a model
        // page for this table. An empty scale sends nothing, which is exactly
        // what these models received before ADR-204; a guessed scale would
        // send a value some of them reject. A requested effort is therefore
        // not applied here and no effort key is recorded, which is how that
        // shows.
        if (str_starts_with($model, 'gpt-5') || preg_match('/^o[1-9]/', $model) === 1) {
            return new OpenAiModelProfile(
                family: str_starts_with($model, 'gpt-5') ? 'gpt-5' : 'o-series',
                isReasoningModel: true,
            );
        }

        return OpenAiModelProfile::unknown($model);
    }

    /**
     * `gpt-6-luna-2026-05-18` and `gpt-6-luna` are one family. Anything past
     * the third segment is a snapshot date.
     */
    private static function gpt6Family(string $model): string
    {
        $parts = explode('-', $model);

        return count($parts) >= 3
            ? implode('-', array_slice($parts, 0, 3))
            : $model;
    }
}
