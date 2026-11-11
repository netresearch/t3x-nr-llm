<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Protocol;

use RuntimeException;

/**
 * A response body a2a-python 1.3.0 sent in the stage-0 spike, as recorded
 * (`Tests/Fixtures/A2a/Wire`).
 */
final readonly class WireFixture
{
    private function __construct(
        public string $requestId,
        public int $status,
        public string $body,
    ) {}

    public static function load(string $name): self
    {
        $path    = __DIR__ . '/../../../../../Fixtures/A2a/Wire/' . $name . '.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_string($decoded['body'] ?? null) || !is_int($decoded['status'] ?? null)) {
            throw new RuntimeException('Unreadable wire fixture ' . $name, 4383919010);
        }

        $requestId = $decoded['requestId'] ?? '';

        return new self(is_string($requestId) ? $requestId : '', $decoded['status'], $decoded['body']);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedBody(): array
    {
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The fixture body is not a JSON object', 1791950970);
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }
}
