<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Request DTO for refreshing task input data AJAX endpoint.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class RefreshInputRequest
{
    public function __construct(
        public int $uid,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $data */
        $data = is_array($body) ? $body : [];

        return new self(
            uid: self::extractInt($data, 'uid'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractInt(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }
}
