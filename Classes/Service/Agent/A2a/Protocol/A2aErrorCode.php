<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * The JSON-RPC error codes the client distinguishes: the JSON-RPC 2.0 set and
 * the A2A codes of §5.4 (A2A-P6).
 *
 * `-32001` to `-32099` belong to A2A; a code in that range this enum does not
 * name (v1.1 already assigns `-32010`) is kept as a number by
 * {@see A2aRemoteError} and has no case here.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum A2aErrorCode: int
{
    case PARSE_ERROR                        = -32700;
    case INVALID_REQUEST                    = -32600;
    case METHOD_NOT_FOUND                   = -32601;
    case INVALID_PARAMS                     = -32602;
    case INTERNAL_ERROR                     = -32603;
    case SERVER_ERROR                       = -32000;
    case TASK_NOT_FOUND                     = -32001;
    case TASK_NOT_CANCELABLE                = -32002;
    case PUSH_NOTIFICATION_NOT_SUPPORTED    = -32003;
    case UNSUPPORTED_OPERATION              = -32004;
    case CONTENT_TYPE_NOT_SUPPORTED         = -32005;
    case INVALID_AGENT_RESPONSE             = -32006;
    case EXTENDED_AGENT_CARD_NOT_CONFIGURED = -32007;
    case EXTENSION_SUPPORT_REQUIRED         = -32008;
    case VERSION_NOT_SUPPORTED              = -32009;

    /**
     * The `ErrorInfo.reason` the protocol pairs with an A2A code (A2A-P6),
     * null for the JSON-RPC codes, which have none.
     */
    public function reason(): ?string
    {
        return match ($this) {
            self::TASK_NOT_FOUND                     => 'TASK_NOT_FOUND',
            self::TASK_NOT_CANCELABLE                => 'TASK_NOT_CANCELABLE',
            self::PUSH_NOTIFICATION_NOT_SUPPORTED    => 'PUSH_NOTIFICATION_NOT_SUPPORTED',
            self::UNSUPPORTED_OPERATION              => 'UNSUPPORTED_OPERATION',
            self::CONTENT_TYPE_NOT_SUPPORTED         => 'CONTENT_TYPE_NOT_SUPPORTED',
            self::INVALID_AGENT_RESPONSE             => 'INVALID_AGENT_RESPONSE',
            self::EXTENDED_AGENT_CARD_NOT_CONFIGURED => 'EXTENDED_AGENT_CARD_NOT_CONFIGURED',
            self::EXTENSION_SUPPORT_REQUIRED         => 'EXTENSION_SUPPORT_REQUIRED',
            self::VERSION_NOT_SUPPORTED              => 'VERSION_NOT_SUPPORTED',
            default                                  => null,
        };
    }

    /**
     * Whether the code is in the range A2A reserves (`-32001` to `-32099`).
     */
    public static function isA2aRange(int $code): bool
    {
        return $code <= -32001 && $code >= -32099;
    }
}
