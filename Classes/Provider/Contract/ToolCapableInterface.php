<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;

interface ToolCapableInterface
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param list<ToolSpec>                   $tools    Typed tool declarations
     *                                                   the model is allowed to
     *                                                   invoke. Provider
     *                                                   implementations are
     *                                                   responsible for any
     *                                                   per-vendor wire-format
     *                                                   conversion (most call
     *                                                   `$spec->toArray()` for
     *                                                   the OpenAI shape; Claude
     *                                                   / Gemini read the typed
     *                                                   fields directly).
     * @param array<string, mixed>             $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse;

    public function supportsTools(): bool;
}
