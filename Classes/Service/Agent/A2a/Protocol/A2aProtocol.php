<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * The fixed facts of the protocol profile nr-llm speaks (spec 051, A2A-P1, A2A-P2).
 *
 * A2A 1.0 over the JSON-RPC binding, nothing else (ADR-278). The method names
 * are the proto's rpc names; the version travels in the `A2A-Version` header
 * on every request.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aProtocol
{
    public const VERSION = '1.0';

    public const VERSION_HEADER = 'A2A-Version';

    public const BINDING_JSONRPC = 'JSONRPC';

    public const CONTENT_TYPE = 'application/json';

    public const METHOD_SEND_MESSAGE = 'SendMessage';

    public const METHOD_GET_TASK = 'GetTask';

    public const METHOD_LIST_TASKS = 'ListTasks';

    public const METHOD_CANCEL_TASK = 'CancelTask';

    /** The domain of an `ErrorInfo` the protocol itself defines (A2A-P6). */
    public const ERROR_DOMAIN = 'a2a-protocol.org';

    /** The domain of an `ErrorInfo` nr-llm defines (A2A-P6). */
    public const NRLLM_ERROR_DOMAIN = 'nr-llm.netresearch.de';

    public const ERROR_INFO_TYPE = 'type.googleapis.com/google.rpc.ErrorInfo';

    /**
     * Whether a protocol version string names a version this client speaks:
     * `1.0` and any `1.0.x`, the patch level ignored (§3.6).
     */
    public static function isSupportedVersion(string $version): bool
    {
        return $version === self::VERSION || str_starts_with($version, self::VERSION . '.');
    }
}
