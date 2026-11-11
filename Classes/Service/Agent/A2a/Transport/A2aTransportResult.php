<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Transport;

/**
 * The result of {@see A2aTransportInterface::send()}.
 *
 * For {@see A2aTransportOutcome::RESPONSE}, `$oversized` says the body was
 * longer than the request's limit; `$body` then holds only the first bytes and
 * must not be parsed (A2A-C8). For the two other outcomes `$reason` is a
 * machine-readable code (`ADDRESS_REFUSED`, `TIMEOUT_AFTER_SEND`, …) and
 * `$detail` says why in words, without any credential.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTransportResult
{
    private function __construct(
        public A2aTransportOutcome $outcome,
        public int $status,
        public string $contentType,
        public string $etag,
        public string $body,
        public bool $oversized,
        public string $reason,
        public string $detail,
    ) {}

    public static function notSent(string $reason, string $detail): self
    {
        return new self(A2aTransportOutcome::NOT_SENT, 0, '', '', '', false, $reason, $detail);
    }

    public static function maybeSent(string $reason, string $detail): self
    {
        return new self(A2aTransportOutcome::MAYBE_SENT, 0, '', '', '', false, $reason, $detail);
    }

    public static function response(int $status, string $contentType, string $etag, string $body, bool $oversized): self
    {
        return new self(A2aTransportOutcome::RESPONSE, $status, $contentType, $etag, $body, $oversized, '', '');
    }

    /**
     * The media type of the response, lower-cased, without parameters.
     */
    public function mediaType(): string
    {
        return strtolower(trim(explode(';', $this->contentType, 2)[0]));
    }
}
