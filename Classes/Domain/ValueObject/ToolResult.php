<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The typed return value of {@see \Netresearch\NrLlm\Service\Tool\ToolInterface::execute()}.
 *
 * SECURITY INVARIANT — egress separation by construction:
 *   `$content` is the ONLY member that may cross the provider wire (it also
 *   egresses to the backend DOM). `$artifacts` are RUN-SCOPED: trace, inspector
 *   and persisted audit only. This class has NO __toString() and NO accessor
 *   that merges an artifact into a wire string, so `->content` is the single
 *   path to a wire string. Both channels are untrusted tool bytes; both are
 *   UTF-8-coerced and byte-bounded in {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}::invoke()
 *   before egress.
 *
 * Fail-closed: {@see self::error()} carries NO artifacts.
 */
final readonly class ToolResult
{
    /**
     * @param list<ToolArtifact> $artifacts
     */
    private function __construct(
        public string $content,
        public bool $isError,
        public array $artifacts,
    ) {}

    /**
     * A non-error result with optional run-only structured artifacts.
     */
    public static function text(string $content, ToolArtifact ...$artifacts): self
    {
        return new self($content, false, array_values($artifacts));
    }

    /**
     * An error result. Fail-closed by construction: NO artifacts ever ride an
     * error result, so a failing tool cannot leak a half-built structure.
     */
    public static function error(string $content): self
    {
        return new self($content, true, []);
    }
}
