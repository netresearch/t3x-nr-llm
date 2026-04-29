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
 * Value Object describing a tool the model is allowed to call.
 *
 * Mirrors the OpenAI / Anthropic / Gemini "function-calling" contract:
 * a typed declaration consisting of a machine name, a free-form
 * human-readable description, and a JSON-Schema parameter object the
 * model fills in. The single supported tool kind is `function`,
 * matching every provider that currently implements
 * :php:`ToolCapableInterface`.
 *
 * Currently a leaf node: the `parameters` field is intentionally a
 * plain `array<string, mixed>` because JSON Schema is unbounded and
 * not worth modelling once again — every provider passes it through
 * verbatim. If a future audit slice picks up parameter validation, a
 * dedicated VO can wrap this property without touching call sites.
 *
 * Pairs with :php:`ToolCall` on the response side: callers declare
 * `ToolSpec` instances; the model returns `ToolCall` instances pointing
 * back at one of those specs by name.
 */
final readonly class ToolSpec implements JsonSerializable
{
    /**
     * The single tool kind every supported provider recognises.
     *
     * Kept as a const rather than an enum because no other variants
     * exist and adding one is a provider-level change anyway.
     */
    public const TYPE_FUNCTION = 'function';

    /**
     * @param array<string, mixed> $parameters JSON-Schema-shaped parameter
     *                                         description; passed verbatim
     *                                         to the provider.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
        public string $type = self::TYPE_FUNCTION,
    ) {
        if ($this->name === '') {
            throw new InvalidArgumentException(
                'ToolSpec name must not be empty.',
                1745410001,
            );
        }
        if ($this->type === '') {
            throw new InvalidArgumentException(
                'ToolSpec type must not be empty.',
                1745410002,
            );
        }
    }

    /**
     * Convenience factory for the dominant `function` kind.
     *
     * @param array<string, mixed> $parameters
     */
    public static function function(string $name, string $description, array $parameters): self
    {
        return new self(
            name: $name,
            description: $description,
            parameters: $parameters,
            type: self::TYPE_FUNCTION,
        );
    }

    /**
     * Reconstruct from the provider wire shape.
     *
     * @param array{
     *     type?: string,
     *     function: array{
     *         name: string,
     *         description?: string,
     *         parameters?: array<string, mixed>,
     *     }
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['function'])) {
            throw new InvalidArgumentException(
                'ToolSpec array missing required "function" key.',
                1745410003,
            );
        }
        $function = $data['function'];
        if (!isset($function['name']) || !\is_string($function['name'])) {
            throw new InvalidArgumentException(
                'ToolSpec function.name must be a non-empty string.',
                1745410004,
            );
        }

        $description = $function['description'] ?? '';
        $parameters  = $function['parameters'] ?? [];
        $type        = $data['type'] ?? self::TYPE_FUNCTION;

        return new self(
            name: $function['name'],
            description: \is_string($description) ? $description : '',
            parameters: \is_array($parameters) ? $parameters : [],
            type: \is_string($type) && $type !== '' ? $type : self::TYPE_FUNCTION,
        );
    }

    /**
     * Serialise to the array shape every provider currently expects on
     * the wire. Idempotent: `ToolSpec::fromArray($spec->toArray()) ==
     * $spec`.
     *
     * @return array{
     *     type: string,
     *     function: array{
     *         name: string,
     *         description: string,
     *         parameters: array<string, mixed>,
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'function' => [
                'name'        => $this->name,
                'description' => $this->description,
                'parameters'  => $this->parameters,
            ],
        ];
    }

    /**
     * @return array{
     *     type: string,
     *     function: array{
     *         name: string,
     *         description: string,
     *         parameters: array<string, mixed>,
     *     }
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
