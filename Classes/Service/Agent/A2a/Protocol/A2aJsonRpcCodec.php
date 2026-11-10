<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

use JsonException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;

/**
 * The JSON-RPC 2.0 envelope of the A2A binding (A2A-P1, §9).
 *
 * Encoding is deterministic: the params are serialised once by
 * {@see self::encodeParams()}, and the envelope embeds exactly those bytes.
 * The send-intent journal hashes the same string (A2A-C4), so two identical
 * requests have one digest whatever JSON-RPC id each envelope carries.
 *
 * Decoding checks the envelope and nothing else: `jsonrpc` is `2.0`, the `id`
 * is the request's (A2A-P4), and exactly one of `result` and `error` is
 * present. Unrecognised members are ignored (A2A-P4, §5.7).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aJsonRpcCodec
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * The params object as sent. An empty params array is `{}`, never `[]`.
     *
     * @param array<string, mixed> $params
     *
     * @throws JsonException
     */
    public static function encodeParams(array $params): string
    {
        return $params === [] ? '{}' : json_encode($params, self::JSON_FLAGS);
    }

    /**
     * The whole request body around params that {@see self::encodeParams()}
     * produced.
     *
     * @throws JsonException
     */
    public static function encodeRequest(string $method, string $encodedParams, string $id): string
    {
        return sprintf(
            '{"jsonrpc":"2.0","id":%s,"method":%s,"params":%s}',
            json_encode($id, self::JSON_FLAGS),
            json_encode($method, self::JSON_FLAGS),
            $encodedParams,
        );
    }

    /**
     * The sha256 of the serialised request params (A2A-C4).
     */
    public static function digest(string $encodedParams): string
    {
        return hash('sha256', $encodedParams);
    }

    /**
     * @throws A2aInvalidAgentResponseException
     */
    public static function decodeResponse(string $body, string $expectedId): A2aJsonRpcResponse
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw A2aInvalidAgentResponseException::notJsonRpc('the body is not JSON');
        }

        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw A2aInvalidAgentResponseException::notJsonRpc('the body is not a JSON object');
        }

        if (($decoded['jsonrpc'] ?? null) !== '2.0') {
            throw A2aInvalidAgentResponseException::notJsonRpc('"jsonrpc" is not "2.0"');
        }

        $hasResult = array_key_exists('result', $decoded);
        $hasError  = array_key_exists('error', $decoded);
        if ($hasResult === $hasError) {
            throw A2aInvalidAgentResponseException::notJsonRpc('it must carry exactly one of "result" and "error"');
        }

        $id = $decoded['id'] ?? null;

        // JSON-RPC 2.0 §5: a server that could not read the request's id
        // answers an error with a null id. Anything else must be ours.
        if ($id !== $expectedId && (!$hasError || $id !== null)) {
            throw A2aInvalidAgentResponseException::idMismatch();
        }

        if ($hasError) {
            return A2aJsonRpcResponse::ofError(A2aErrorMapper::fromJsonRpcError($decoded['error']));
        }

        $result = $decoded['result'];
        if (!is_array($result) || array_is_list($result) && $result !== []) {
            throw A2aInvalidAgentResponseException::invalidField('result', 'not an object');
        }

        $object = [];
        foreach ($result as $key => $value) {
            $object[(string) $key] = $value;
        }

        return A2aJsonRpcResponse::ofResult($object);
    }
}
