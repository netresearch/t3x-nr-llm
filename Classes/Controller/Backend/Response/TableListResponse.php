<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for the record-picker's "list available tables" AJAX action.
 *
 * @internal
 */
final readonly class TableListResponse implements JsonSerializable
{
    /**
     * @param list<array{name: string, label: string}> $tables Tables sorted by display label
     */
    public function __construct(
        public array $tables,
        public bool $success = true,
    ) {}

    /**
     * @return array{success: bool, tables: list<array{name: string, label: string}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'tables'  => $this->tables,
        ];
    }
}
