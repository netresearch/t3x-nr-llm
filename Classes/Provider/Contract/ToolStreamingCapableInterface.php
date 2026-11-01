<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Closure;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;

/**
 * A provider that can hand out the text of a tool round while it is written.
 *
 * The call stays what {@see ToolCapableInterface::chatCompletionWithTools()} is:
 * it blocks and returns the complete response, tool calls and usage included,
 * so everything around it stays as it is (ADR-240). The text only reaches the
 * sink earlier. Whatever the sink received is part of the returned response's
 * content. A provider that cannot stream a call says so through
 * {@see self::streamsToolCalls()}, and the caller sends it blocking.
 */
interface ToolStreamingCapableInterface extends ToolCapableInterface
{
    /**
     * Whether a call with these options can stream.
     *
     * @param array<string, mixed> $options
     */
    public function streamsToolCalls(array $options): bool;

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec>                         $tools
     * @param array<string, mixed>                   $options
     * @param Closure(string): void                  $onText   receives each piece of text
     */
    public function chatCompletionWithToolsStreamingText(array $messages, array $tools, array $options, Closure $onText): CompletionResponse;
}
