<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Request DTO for loading full record data AJAX endpoint.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class LoadRecordDataRequest
{
    /**
     * @param list<int> $uidList Parsed list of valid UIDs
     */
    public function __construct(
        public string $table,
        public string $uids,
        public array $uidList,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $data */
        $data = is_array($body) ? $body : [];

        $table = self::extractString($data, 'table');
        $uids = self::extractString($data, 'uids');

        // Parse UIDs into validated integer list
        $uidList = [];
        if ($uids !== '') {
            $uidList = array_values(array_filter(
                array_map(intval(...), explode(',', $uids)),
                static fn(int $uid): bool => $uid > 0,
            ));
        }

        return new self(
            table: $table,
            uids: $uids,
            uidList: $uidList,
        );
    }

    public function isValid(): bool
    {
        return $this->table !== '' && $this->uidList !== [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractString(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        return is_scalar($value) ? (string)$value : $default;
    }
}
