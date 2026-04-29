<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Value Object representing a single tool invocation the model emitted.
 *
 * Pairs with :php:`ToolSpec` — the caller declares specs, the model
 * answers with calls. Each call carries the provider-issued correlation
 * id (so a follow-up `tool` role message can reference it), the name of
 * the spec being invoked, and the arguments the model chose, parsed
 * into an associative array.
 *
 * Replaces the nested associative-array shape currently produced by
 * :php:`OpenAiProvider::chatCompletion()` (`Classes/Provider/OpenAiProvider.php:157-175`)
 * and stored on :php:`CompletionResponse::$toolCalls` as
 * `array<int, array<string, mixed>>`. The legacy array shape is
 * preserved verbatim by `toArray()` so the migration of providers and
 * `CompletionResponse` can land in a follow-up slice without
 * disturbing this data model.
 */
final readonly class ToolCall implements JsonSerializable
{
    /** @see ToolSpec::TYPE_FUNCTION */
    public const TYPE_FUNCTION = 'function';

    /**
     * @param string               $id        Provider-issued identifier; the
     *                                        caller echoes this back when it
     *                                        sends the tool's result.
     * @param string               $name      Name of the `ToolSpec` this call
     *                                        targets.
     * @param array<string, mixed> $arguments Parsed JSON-decoded argument map.
     *                                        Empty array when the model
     *                                        produced an empty / malformed
     *                                        argument string.
     * @param string               $type      Tool kind; only `function`
     *                                        currently exists on the wire.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public string $type = self::TYPE_FUNCTION,
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException(
                'ToolCall id must not be empty.',
                1745411001,
            );
        }
        if ($this->name === '') {
            throw new InvalidArgumentException(
                'ToolCall name must not be empty.',
                1745411002,
            );
        }
        if ($this->type === '') {
            throw new InvalidArgumentException(
                'ToolCall type must not be empty.',
                1745411003,
            );
        }
    }

    /**
     * Reconstruct from the OpenAI / Anthropic-aligned wire shape.
     *
     * Accepts the on-the-wire variant where `function.arguments` is a
     * JSON string (what every provider actually returns) AND the
     * already-decoded variant where it is a map (what the legacy
     * extractor in OpenAiProvider currently produces). The input
     * variation is what makes calling code today so noisy — this
     * factory normalises it once.
     *
     * @param array{
     *     id?: string,
     *     type?: string,
     *     function?: array{
     *         name?: string,
     *         arguments?: string|array<string, mixed>,
     *     },
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $function = $data['function'] ?? [];
        if (!\is_array($function)) {
            $function = [];
        }

        $rawArgs   = $function['arguments'] ?? [];
        $arguments = self::normaliseArguments($rawArgs);

        $id   = $data['id'] ?? '';
        $name = $function['name'] ?? '';
        $type = $data['type'] ?? self::TYPE_FUNCTION;

        return new self(
            id: \is_string($id) ? $id : '',
            name: \is_string($name) ? $name : '',
            arguments: $arguments,
            type: \is_string($type) && $type !== '' ? $type : self::TYPE_FUNCTION,
        );
    }

    /**
     * Convenience factory mirroring `ToolSpec::function()`.
     *
     * @param array<string, mixed> $arguments
     */
    public static function function(string $id, string $name, array $arguments): self
    {
        return new self(
            id: $id,
            name: $name,
            arguments: $arguments,
            type: self::TYPE_FUNCTION,
        );
    }

    /**
     * Serialise to the array shape `OpenAiProvider` currently emits.
     * Idempotent against `fromArray()` for either input variant of
     * `function.arguments`.
     *
     * @return array{
     *     id: string,
     *     type: string,
     *     function: array{
     *         name: string,
     *         arguments: array<string, mixed>,
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'id'   => $this->id,
            'type' => $this->type,
            'function' => [
                'name'      => $this->name,
                'arguments' => $this->arguments,
            ],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     function: array{
     *         name: string,
     *         arguments: array<string, mixed>,
     *     }
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Decode a wire-shape `arguments` value into a plain array.
     *
     * @return array<string, mixed>
     */
    private static function normaliseArguments(mixed $raw): array
    {
        if (\is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }
}
