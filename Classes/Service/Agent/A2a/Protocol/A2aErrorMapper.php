<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;

/**
 * Reads a JSON-RPC `error` member into an {@see A2aRemoteError} (A2A-P6, A2A-D6).
 *
 * `error.data` is an array of ProtoJSON `Any` objects, each with its type URL;
 * the first
 * `google.rpc.ErrorInfo` among them supplies reason, domain and metadata.
 * Entries without a type URL, and a `data` that is not an array at all, are
 * not details and are dropped; neither makes the error unreadable, because
 * the code alone already says what happened.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aErrorMapper
{
    /**
     * @throws A2aInvalidAgentResponseException when the error object lacks its code or message
     */
    public static function fromJsonRpcError(mixed $error): A2aRemoteError
    {
        if (!is_array($error)) {
            throw A2aInvalidAgentResponseException::invalidField('error', 'not an object');
        }

        $code = $error['code'] ?? null;
        if (!is_int($code)) {
            throw A2aInvalidAgentResponseException::missingField('error.code');
        }

        $message = $error['message'] ?? null;
        if (!is_string($message)) {
            throw A2aInvalidAgentResponseException::missingField('error.message');
        }

        $details = self::details($error['data'] ?? null);
        foreach ($details as $detail) {
            if ($detail['@type'] !== A2aProtocol::ERROR_INFO_TYPE) {
                continue;
            }

            return new A2aRemoteError(
                $code,
                $message,
                is_string($detail['reason'] ?? null) ? $detail['reason'] : '',
                is_string($detail['domain'] ?? null) ? $detail['domain'] : '',
                self::stringMap($detail['metadata'] ?? null),
                $details,
            );
        }

        return new A2aRemoteError($code, $message, details: $details);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function details(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return [];
        }

        $details = [];
        foreach ($data as $entry) {
            if (!is_array($entry) || !is_string($entry['@type'] ?? null)) {
                continue;
            }

            $detail = [];
            foreach ($entry as $key => $value) {
                $detail[(string) $key] = $value;
            }

            $details[] = $detail;
        }

        return $details;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $entry) {
            if (is_string($entry)) {
                $map[(string) $key] = $entry;
            }
        }

        return $map;
    }
}
