<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ArtifactType;

/**
 * One structured, run-scoped result fragment a tool may attach ALONGSIDE its
 * text {@see ToolResult::$content} for richer backend rendering.
 *
 * An artifact NEVER reaches the external provider — the runtime places only
 * `content` on the wire (see {@see ToolResult}). It egresses to the backend DOM
 * (Tool Playground inspector) and the persisted audit stream ONLY.
 *
 * Both `label` and every string leaf of `$data` are UNTRUSTED tool bytes: the
 * runtime ({@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}) UTF-8-coerces
 * and byte-bounds them before egress. An emitting tool MUST apply the SAME
 * redaction to `$data` that it applies to its text output — an artifact must
 * never re-expose a field the text path deliberately withheld.
 */
final readonly class ToolArtifact
{
    /**
     * @param array<array-key, mixed> $data JSON-serialisable payload matching
     *                                      the shape declared by $type.
     */
    public function __construct(
        public ArtifactType $type,
        public string $label,
        public array $data,
    ) {}

    /**
     * @return array{type: string, label: string, data: array<array-key, mixed>}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'label' => $this->label, 'data' => $this->data];
    }
}
