<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for the record-picker's "load full row data" AJAX action.
 *
 * The `data` field is the JSON-encoded list of selected rows (already
 * stringified for direct insertion into the Task input field — the
 * frontend doesn't re-encode).
 *
 * @internal
 */
final readonly class RecordDataResponse implements JsonSerializable
{
    public function __construct(
        public string $data,
        public int $recordCount,
        public bool $success = true,
    ) {}

    /**
     * @return array{success: bool, data: string, recordCount: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'success'     => $this->success,
            'data'        => $this->data,
            'recordCount' => $this->recordCount,
        ];
    }
}
