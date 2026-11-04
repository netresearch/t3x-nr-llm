<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * What one request of the OAuth flow returned, already bounded (ADR-259).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthHttpResponse
{
    public function __construct(
        public int $status,
        public string $contentType,
        public string $wwwAuthenticate,
        public string $body,
    ) {}

    public function mediaType(): string
    {
        return strtolower(trim(explode(';', $this->contentType, 2)[0]));
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
