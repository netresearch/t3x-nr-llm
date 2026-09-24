<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

/**
 * The keys this adapter writes into `CompletionResponse::$metadata`
 * (ADR-203, ADR-204).
 *
 * They live on the existing metadata slot rather than as new fields because
 * `CompletionResponse` is frozen in `api-surface.txt`, and because
 * `GuardrailMiddleware` already carries `metadata` across the response it
 * rebuilds after screening — a new constructor field would be dropped there.
 *
 * Every key is optional and **an absent key is a statement**: no transport key
 * means the response predates this change or came from another adapter, and no
 * effort key means no reasoning effort was applied, which is different from an
 * effort of `none` having been applied.
 */
final class OpenAiCallMetadata
{
    /** `chat/completions` or `responses` — which endpoint served this call. */
    public const KEY_TRANSPORT = 'nrllm_transport';

    /**
     * The reasoning effort that was actually applied, as the backed value of
     * {@see \Netresearch\NrLlm\Domain\Enum\ReasoningEffort}. On the Responses
     * transport it is read from the provider's own `reasoning.effort`; on
     * Chat Completions, where no such field comes back, it is what was sent.
     * {@see self::KEY_EFFORT_SOURCE} says which of the two it is.
     */
    public const KEY_REASONING_EFFORT = 'nrllm_reasoning_effort';

    /**
     * `provider` when the effort was read back from the response, `request`
     * when it is what this extension sent.
     */
    public const KEY_EFFORT_SOURCE = 'nrllm_reasoning_effort_source';

    /**
     * The provider's own response items for this turn, to be replayed into the
     * next request untouched.
     *
     * @see \Netresearch\NrLlm\Domain\ValueObject\ChatMessage::$providerItems
     */
    public const KEY_PROVIDER_ITEMS = 'nrllm_provider_items';

    public const TRANSPORT_CHAT_COMPLETIONS = 'chat/completions';

    public const TRANSPORT_RESPONSES = 'responses';

    public const EFFORT_SOURCE_PROVIDER = 'provider';

    public const EFFORT_SOURCE_REQUEST = 'request';
}
