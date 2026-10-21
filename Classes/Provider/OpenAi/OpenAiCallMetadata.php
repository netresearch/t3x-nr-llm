<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

/**
 * OpenAI response metadata keys and the shared opaque replay slot.
 * Existing CompletionResponse metadata carries these values through
 * GuardrailMiddleware reconstruction without a new public field.
 * Transport and effort keys belong to OpenAI. KEY_PROVIDER_ITEMS also carries
 * the explicitly owned Gemini replay capsule (ADR-203/204/222).
 * Keys remain optional: absence means no state was supplied for that key.
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
