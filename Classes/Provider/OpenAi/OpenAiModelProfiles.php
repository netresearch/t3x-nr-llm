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
 * The named GPT-6 entries match the exact family name and a `-YYYY-MM-DD`
 * snapshot. Their effort scales are the values OpenAI's own HTTP 400 lists as
 * supported, measured against `api.openai.com` on 2026-11-09 for all five
 * listed GPT-6 ids; the model pages read on 2026-09-23 also named `max`,
 * which the API refuses for every one of them. The GPT-5 and o-series entry
 * carries only what the replaced regex already decided.
 *
 * Any other id starting with `gpt-6` gets the GPT-6 family default: a
 * reasoning model whose tools go through Responses, with no effort scale.
 * Every measured GPT-6 model refuses a non-default `temperature` and refuses
 * function tools on `chat/completions` at its default effort, so the family
 * shares those two facts; the scale differs per model, so none is granted.
 *
 * A model id that matches nothing gets {@see OpenAiModelProfile::unknown()}.
 * That is the answer for a compatible gateway's deployment name as well, and
 * it is why an unknown id keeps the transport it has always used.
 */
final class OpenAiModelProfiles
{
    /**
     * The lowest output limit sent to a reasoning model, thinking included
     * (ADR-241). It bounds nothing a person meant to bound, because what is not
     * used is not paid, and it is well below the limit of these families.
     */
    public const REASONING_OUTPUT_FLOOR = 16000;

    /**
     * The scale `gpt-6-sol`, `gpt-6-luna` and `gpt-6.1-terra` accept: `none`
     * to `xhigh`. `minimal` and `max` are refused with HTTP 400.
     *
     * @var list<ReasoningEffort>
     */
    private const GPT6_EFFORTS = [
        ReasoningEffort::None,
        ReasoningEffort::Low,
        ReasoningEffort::Medium,
        ReasoningEffort::High,
        ReasoningEffort::XHigh,
    ];

    /**
     * The scale `gpt-6-astra` and `gpt-6.1-sol` accept: the same without `none`.
     *
     * @var list<ReasoningEffort>
     */
    private const GPT6_ASTRA_EFFORTS = [
        ReasoningEffort::Low,
        ReasoningEffort::Medium,
        ReasoningEffort::High,
        ReasoningEffort::XHigh,
    ];

    public static function forModel(string $model): OpenAiModelProfile
    {
        $model = strtolower(trim($model));
        if (preg_match('/^gpt-6\.1-sol(?:-\d{4}-\d{2}-\d{2})?$/', $model) === 1) {
            return new OpenAiModelProfile(
                family: 'gpt-6.1-sol',
                isReasoningModel: true,
                supportedEfforts: self::GPT6_ASTRA_EFFORTS,
                defaultEffort: ReasoningEffort::Medium,
                toolsOnChatCompletions: false,
            );
        }

        // GPT-6 Astra: no `none` effort, and Chat Completions serves it no
        // function calling under any setting.
        if (preg_match('/^gpt-6-astra(?:-\d{4}-\d{2}-\d{2})?$/', $model) === 1) {
            return new OpenAiModelProfile(
                family: 'gpt-6-astra',
                isReasoningModel: true,
                supportedEfforts: self::GPT6_ASTRA_EFFORTS,
                defaultEffort: ReasoningEffort::Medium,
                toolsOnChatCompletions: false,
            );
        }

        // GPT-6 Sol, Luna and 6.1 Terra: Chat Completions serves function
        // calling only at `none`, so this extension does not use it for tools.
        if (preg_match('/^(?:gpt-6-(?:sol|luna)|gpt-6\.1-terra)(?:-\d{4}-\d{2}-\d{2})?$/', $model) === 1) {
            return new OpenAiModelProfile(
                family: self::gpt6Family($model),
                isReasoningModel: true,
                supportedEfforts: self::GPT6_EFFORTS,
                defaultEffort: ReasoningEffort::Medium,
                toolsOnChatCompletions: false,
            );
        }

        // Any other GPT-6 id: a member of the family this table does not name
        // yet (`gpt-6.1-terra` was one until 2026-11-09). It keeps the two
        // facts every measured GPT-6 model shares — no sampling parameters,
        // tools through Responses — and gets no effort scale, so no effort is
        // sent and none can be refused.
        if (str_starts_with($model, 'gpt-6')) {
            return new OpenAiModelProfile(
                family: 'gpt-6',
                isReasoningModel: true,
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
