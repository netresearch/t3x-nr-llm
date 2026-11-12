<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;

/**
 * One refusal of the A2A server, rendered as a JSON-RPC error object (spec
 * 051 A2A-P6).
 *
 * `error.data` is always an array of `@var` objects. Every error carries a
 * `google.rpc.ErrorInfo`: an A2A code (`-32001` to `-32009`) with the reason
 * the protocol pairs with it and the domain `a2a-protocol.org`, unless an
 * nr-llm reason is named; every other code with an nr-llm reason and the
 * domain `nr-llm.netresearch.de`. The message never names an internal detail.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aRpcError extends A2aException
{
    public const REASON_UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const REASON_RATE_LIMITED = 'RATE_LIMITED';

    public const REASON_PROFILE_REQUIRED = 'PROFILE_REQUIRED';

    public const REASON_PROFILE_NOT_ALLOWED = 'PROFILE_NOT_ALLOWED';

    public const REASON_PROFILE_MISMATCH = 'PROFILE_MISMATCH';

    public const REASON_RESULT_NOT_RETAINABLE = 'RESULT_NOT_RETAINABLE';

    public const REASON_WORKER_UNAVAILABLE = 'WORKER_UNAVAILABLE';

    public const REASON_CLIENT_CAPACITY_EXHAUSTED = 'CLIENT_CAPACITY_EXHAUSTED';

    public const REASON_PROFILE_CAPACITY_EXHAUSTED = 'PROFILE_CAPACITY_EXHAUSTED';

    public const REASON_WAITING_CAPACITY_EXHAUSTED = 'WAITING_CAPACITY_EXHAUSTED';

    public const REASON_BLOCKING_DEADLINE_EXCEEDED = 'BLOCKING_DEADLINE_EXCEEDED';

    public const REASON_INVALID_PARAMS = 'INVALID_PARAMS';

    public const REASON_INTERNAL = 'INTERNAL';

    public const REASON_CONTINUATION_NOT_SUPPORTED = 'CONTINUATION_NOT_SUPPORTED';

    /** The profile's configuration is switched off, or not one its client's technical user may use. */
    public const REASON_PROFILE_UNAVAILABLE = 'PROFILE_UNAVAILABLE';

    private const BAD_REQUEST_TYPE = 'type.googleapis.com/google.rpc.BadRequest';

    private const RETRY_INFO_TYPE = 'type.googleapis.com/google.rpc.RetryInfo';

    /**
     * @param array<string, string>                           $metadata   ErrorInfo.metadata
     * @param list<array{field: string, description: string}> $violations BadRequest.fieldViolations
     * @param array<string, string>                           $headers    HTTP headers of the response
     */
    private function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly string $reason,
        public readonly string $domain,
        public readonly int $httpStatus = 200,
        public readonly array $metadata = [],
        public readonly array $violations = [],
        public readonly int $retryAfterSeconds = 0,
        public readonly array $headers = [],
    ) {
        parent::__construct($message, 1799300000 - $rpcCode);
    }

    /**
     * An A2A error of §5.4 with the reason the protocol pairs with it, or an
     * nr-llm reason in the nr-llm domain.
     *
     * @param array<string, string> $metadata
     */
    public static function a2a(A2aErrorCode $code, string $message, string $nrLlmReason = '', array $metadata = []): self
    {
        return new self(
            $code->value,
            $message,
            $nrLlmReason !== '' ? $nrLlmReason : ($code->reason() ?? self::REASON_INTERNAL),
            $nrLlmReason !== '' ? A2aProtocol::NRLLM_ERROR_DOMAIN : A2aProtocol::ERROR_DOMAIN,
            200,
            $metadata,
        );
    }

    /**
     * `-32602`, with a `google.rpc.BadRequest` naming the field.
     */
    public static function invalidParams(string $field, string $description, string $reason = self::REASON_INVALID_PARAMS): self
    {
        return new self(
            A2aErrorCode::INVALID_PARAMS->value,
            'Invalid params: ' . $description,
            $reason,
            A2aProtocol::NRLLM_ERROR_DOMAIN,
            200,
            [],
            [['field' => $field, 'description' => $description]],
        );
    }

    public static function parseError(): self
    {
        return new self(A2aErrorCode::PARSE_ERROR->value, 'The request body is not JSON.', 'PARSE_ERROR', A2aProtocol::NRLLM_ERROR_DOMAIN);
    }

    public static function invalidRequest(string $description): self
    {
        return new self(A2aErrorCode::INVALID_REQUEST->value, 'Invalid request: ' . $description, 'INVALID_REQUEST', A2aProtocol::NRLLM_ERROR_DOMAIN);
    }

    /**
     * HTTP 413, before the body is parsed (A2A-SEC6).
     */
    public static function tooLarge(): self
    {
        return new self(A2aErrorCode::INVALID_REQUEST->value, 'The request body is too large.', 'REQUEST_TOO_LARGE', A2aProtocol::NRLLM_ERROR_DOMAIN, 413);
    }

    public static function methodNotFound(): self
    {
        return new self(A2aErrorCode::METHOD_NOT_FOUND->value, 'Method not found.', 'METHOD_NOT_FOUND', A2aProtocol::NRLLM_ERROR_DOMAIN);
    }

    /**
     * HTTP 401 with `WWW-Authenticate`; one response for a missing, unknown,
     * wrong, disabled and expired credential alike (A2A-SEC1).
     */
    public static function unauthenticated(): self
    {
        return new self(
            A2aErrorCode::SERVER_ERROR->value,
            'Authentication required.',
            self::REASON_UNAUTHENTICATED,
            A2aProtocol::NRLLM_ERROR_DOMAIN,
            401,
            [],
            [],
            0,
            ['WWW-Authenticate' => 'Bearer realm="a2a"'],
        );
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(A2aErrorCode::SERVER_ERROR->value, 'Too many failed attempts.', self::REASON_RATE_LIMITED, A2aProtocol::NRLLM_ERROR_DOMAIN, 429, [], [], max(1, $retryAfter));
    }

    /**
     * A refusal before a task exists (A2A-SV9): HTTP 503, `Retry-After` and a
     * `google.rpc.RetryInfo`.
     */
    public static function unavailable(string $reason, int $retryAfter): self
    {
        return new self(A2aErrorCode::SERVER_ERROR->value, 'The server cannot accept the task now.', $reason, A2aProtocol::NRLLM_ERROR_DOMAIN, 503, [], [], max(1, $retryAfter));
    }

    public static function deadlineExceeded(string $taskId): self
    {
        return new self(
            A2aErrorCode::INTERNAL_ERROR->value,
            'The task did not finish within the blocking wait; retrieve it with GetTask.',
            self::REASON_BLOCKING_DEADLINE_EXCEEDED,
            A2aProtocol::NRLLM_ERROR_DOMAIN,
            504,
            ['taskId' => $taskId],
        );
    }

    public static function internal(): self
    {
        return new self(A2aErrorCode::INTERNAL_ERROR->value, 'Internal error.', self::REASON_INTERNAL, A2aProtocol::NRLLM_ERROR_DOMAIN, 500);
    }

    /**
     * The JSON-RPC error object.
     *
     * @return array{code: int, message: string, data: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $info = ['@type' => A2aProtocol::ERROR_INFO_TYPE, 'reason' => $this->reason, 'domain' => $this->domain];
        if ($this->metadata !== []) {
            $info['metadata'] = $this->metadata;
        }

        $data = [$info];
        if ($this->violations !== []) {
            $data[] = ['@type' => self::BAD_REQUEST_TYPE, 'fieldViolations' => $this->violations];
        }

        if ($this->retryAfterSeconds > 0) {
            $data[] = ['@type' => self::RETRY_INFO_TYPE, 'retryDelay' => $this->retryAfterSeconds . 's'];
        }

        return ['code' => $this->rpcCode, 'message' => $this->getMessage(), 'data' => $data];
    }

    /**
     * @return array<string, string>
     */
    public function responseHeaders(): array
    {
        return $this->retryAfterSeconds > 0 ? $this->headers + ['Retry-After' => (string) $this->retryAfterSeconds] : $this->headers;
    }
}
