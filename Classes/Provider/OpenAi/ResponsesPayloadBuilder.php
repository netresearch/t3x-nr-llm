<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

use Netresearch\NrLlm\Domain\Enum\MessageRole;
use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;

use Netresearch\NrLlm\Provider\Gemini\GeminiReplayMetadata;
use stdClass;

/**
 * Builds a POST /v1/responses payload from nr_llm messages (ADR-203/222).
 * System prompts become instructions; tool definitions and structured-output
 * settings use Responses vocabulary. Historical native OpenAI items replay
 * verbatim. Gemini-owned turns reconstruct their visible text and ordinary
 * calls, never forwarding foreign native parts or the internal wrapper.
 */
final class ResponsesPayloadBuilder
{
    /**
     * @param list<ChatMessage|array<string, mixed>> $messages value objects,
     *                                                         and the array shape
     *                                                         for a multimodal
     *                                                         message `ChatMessage`
     *                                                         does not model
     * @param list<ToolSpec>                         $tools
     * @param array<string, mixed>                   $extra    already-mapped
     *                                                         payload keys the
     *                                                         caller owns: `text`,
     *                                                         `tool_choice`,
     *                                                         sampling parameters
     *
     * @return array<string, mixed>
     */
    public function build(
        string $model,
        array $messages,
        array $tools,
        int $maxOutputTokens,
        ?ReasoningEffort $effort,
        array $extra = [],
    ): array {
        [$instructions, $input] = $this->splitInstructions($messages);

        $payload = [
            'model' => $model,
            'input' => $input,
            'max_output_tokens' => $maxOutputTokens,
            // Stateless by decision, not by omission: the installation has not
            // agreed to OpenAI retaining its conversations, and stateless mode
            // is what makes the reasoning items come back with
            // `encrypted_content` so they can be replayed (ADR-203).
            'store' => false,
        ];

        if ($instructions !== '') {
            $payload['instructions'] = $instructions;
        }

        if ($tools !== []) {
            $payload['tools'] = array_map(
                static fn(ToolSpec $spec): array => [
                    'type' => $spec->type,
                    'name' => $spec->name,
                    'description' => $spec->description,
                    'parameters' => $spec->parameters,
                    // Explicitly non-strict. With `strict` omitted, Responses
                    // tries to rewrite a schema into strict mode — every
                    // property required — and only falls back when it cannot,
                    // while Chat Completions is non-strict by default. The
                    // tool schemas here are written for the non-strict
                    // contract, so the transport must not change it.
                    'strict' => false,
                ],
                $tools,
            );
        }

        if ($effort instanceof ReasoningEffort) {
            $payload['reasoning'] = ['effort' => $effort->value];
        }

        return $payload + $extra;
    }

    /**
     * Translate a Chat Completions `response_format` into `text.format`.
     *
     * The two carry the same information in different shapes: Chat Completions
     * nests the schema under a `json_schema` key, Responses puts `name`,
     * `schema` and `strict` next to the type. `json_object` and `text` pass
     * through as they are.
     *
     * @param array<string, mixed>|null $responseFormat
     *
     * @return array<string, mixed>|null
     */
    public function textFormat(?array $responseFormat): ?array
    {
        if ($responseFormat === null) {
            return null;
        }

        $type = $responseFormat['type'] ?? null;
        if (!is_string($type)) {
            return null;
        }

        if ($type !== 'json_schema') {
            return ['format' => ['type' => $type]];
        }

        $schemaConfig = $responseFormat['json_schema'] ?? null;
        if (!is_array($schemaConfig)) {
            return ['format' => ['type' => 'json_object']];
        }

        $format = ['type' => 'json_schema'];
        foreach (['name', 'schema', 'description', 'strict'] as $key) {
            if (array_key_exists($key, $schemaConfig)) {
                $format[$key] = $schemaConfig[$key];
            }
        }

        return ['format' => $format];
    }

    /**
     * Split the system prompt out of the transcript and map the rest to input
     * items.
     *
     * Several system messages are joined with a blank line. The Responses API
     * takes one `instructions` string, and dropping all but the first would
     * lose a skill block or a snippet the configuration added.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function splitInstructions(array $messages): array
    {
        $instructions = [];
        $input        = [];

        foreach ($messages as $message) {
            if (is_array($message) && array_key_exists('provider_items', $message)) {
                $message = ChatMessage::fromArray($message);
            }

            $role = $message instanceof ChatMessage ? $message->role : ($message['role'] ?? null);

            if ($role === MessageRole::SYSTEM->value) {
                $text = $message instanceof ChatMessage ? $message->content : $this->textOfParts($message['content'] ?? null);
                if ($text !== '') {
                    $instructions[] = $text;
                }

                continue;
            }

            $items = $message instanceof ChatMessage ? $this->itemsFor($message) : [$this->multimodalItem($message)];
            foreach ($items as $item) {
                $input[] = $item;
            }
        }

        return [implode("\n\n", $instructions), $input];
    }

    /**
     * A message whose content is a list of parts — text and images — mapped
     * to the Responses part vocabulary.
     *
     * Chat Completions nests an image as `{type: image_url, image_url: {url}}`;
     * Responses takes `{type: input_image, image_url: <url>}`, the URL as a
     * plain string, and `input_text` for text. A part of any other type is
     * refused by name rather than dropped, because a request that silently
     * lost an image would answer a different question.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function multimodalItem(array $message): array
    {
        $role    = is_string($message['role'] ?? null) ? $message['role'] : MessageRole::USER->value;
        $content = $message['content'] ?? null;

        if (is_string($content)) {
            return ['role' => $role, 'content' => $content];
        }

        if (!is_array($content)) {
            throw new UnsupportedFeatureException(
                'A message without text or content parts cannot be sent to the OpenAI Responses API.',
                1758600101,
            );
        }

        $textType = $role === MessageRole::ASSISTANT->value ? 'output_text' : 'input_text';
        $parts    = [];
        foreach ($content as $part) {
            $type = is_array($part) ? ($part['type'] ?? null) : null;

            if ($type === 'text' && is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = ['type' => $textType, 'text' => $part['text']];

                continue;
            }

            if ($type === 'image_url' && is_array($part)) {
                $image = $part['image_url'] ?? null;
                $url   = is_array($image) ? ($image['url'] ?? null) : $image;
                if (is_string($url) && $url !== '') {
                    $item = ['type' => 'input_image', 'image_url' => $url];
                    if (is_array($image) && is_string($image['detail'] ?? null)) {
                        $item['detail'] = $image['detail'];
                    }

                    $parts[] = $item;

                    continue;
                }
            }

            throw new UnsupportedFeatureException(
                sprintf(
                    'A content part of type "%s" cannot be sent to the OpenAI Responses API.',
                    is_string($type) ? $type : get_debug_type($part),
                ),
                1758600102,
            );
        }

        return ['role' => $role, 'content' => $parts];
    }

    /**
     * The text of a system message that arrived as content parts.
     */
    private function textOfParts(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        return implode("\n", $texts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemsFor(ChatMessage $message): array
    {
        if ($message->getRole() === MessageRole::TOOL) {
            return [
                [
                    'type' => 'function_call_output',
                    'call_id' => $message->toolCallId ?? '',
                    'output' => $message->content,
                ],
            ];
        }

        if ($message->getRole() !== MessageRole::ASSISTANT) {
            return [['role' => $message->role, 'content' => $message->content]];
        }

        $geminiParts = GeminiReplayMetadata::nativeParts($message->providerItems);
        // Untagged historical OpenAI-native items still occupy the entire turn.
        if ($geminiParts === null && $message->providerItems !== null && count($message->providerItems) > 0) {
            return $message->providerItems;
        }

        if ($message->toolCalls !== null) {
            $items = array_map(
                static fn(ToolCall $call): array => [
                    'type' => 'function_call',
                    'call_id' => $call->id,
                    'name' => $call->name,
                    'arguments' => json_encode(
                        $call->arguments !== [] ? $call->arguments : new stdClass(),
                        JSON_THROW_ON_ERROR,
                    ),
                ],
                $message->toolCalls,
            );
            // Foreign reconstruction must retain visible text as well as calls.
            // Never forward Gemini native parts or its internal ownership wrapper.
            if ($geminiParts !== null && $message->content !== '') {
                array_unshift(
                    $items,
                    ['role' => $message->role, 'content' => $message->content],
                );
            }

            return $items;
        }

        return [['role' => $message->role, 'content' => $message->content]];
    }
}
