<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for the record-picker's "fetch records" AJAX action.
 *
 * Carries the lightweight (uid + label) preview shape consumed by the
 * picker dropdown — see `RecordTableReader::fetchSampleRecords()`.
 *
 * @internal
 */
final readonly class RecordListResponse implements JsonSerializable
{
    /**
     * @param list<array{uid: int, label: string}> $records
     */
    public function __construct(
        public array $records,
        public string $labelField,
        public int $total,
        public bool $success = true,
    ) {}

    /**
     * @return array{success: bool, records: list<array{uid: int, label: string}>, labelField: string, total: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'success'    => $this->success,
            'records'    => $this->records,
            'labelField' => $this->labelField,
            'total'      => $this->total,
        ];
    }
}
