<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What kind of thing a request is, structurally (ADR-156).
 *
 * The "task type" axis of the complexity observation. It is deliberately NOT
 * the operation: `tx_nrllm_telemetry.operation` already names the entry point
 * (chat, tools, stream), and repeating it under another column heading would
 * measure nothing new. This names the shape of the payload instead — one
 * question, a conversation, or a tool-assisted transcript — which is the axis a
 * later complexity router would have to branch on.
 *
 * Three cases, because three are what the message list can actually
 * distinguish. A fourth for multimodal is not declared: {@see \Netresearch\NrLlm\Domain\ValueObject\ChatMessage}
 * carries `content` as a string, so nothing in a normalised transcript could
 * ever set it, and a case nothing can reach reads as a measurement that always
 * comes back negative.
 *
 * @internal
 */
enum RequestShape: string
{
    /**
     * One non-system message: a single question with no history.
     */
    case SINGLE_TURN = 'singleTurn';

    /**
     * Several non-system messages, none of them tool traffic.
     */
    case MULTI_TURN = 'multiTurn';

    /**
     * The send carries tool schemas, a tool call or a tool result. Ranked above
     * the turn count because a two-message tool exchange and a twenty-message
     * one are the same kind of request, and the turn count is recorded
     * separately anyway.
     */
    case TOOL_ASSISTED = 'toolAssisted';
}
