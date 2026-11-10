<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

use DateTimeImmutable;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * The filter of a `ListTasksRequest` (proto `ListTasksRequest`), without the
 * tenant, which the client sets from the selected interface (A2A-C2).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aListTasksQuery
{
    public function __construct(
        public string $contextId = '',
        public ?A2aTaskState $status = null,
        public ?int $pageSize = null,
        public string $pageToken = '',
        public ?int $historyLength = null,
        public ?DateTimeImmutable $statusTimestampAfter = null,
        public ?bool $includeArtifacts = null,
    ) {
        if ($pageSize !== null && ($pageSize < 1 || $pageSize > 100)) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100.', 1799100011);
        }

        if ($historyLength !== null && $historyLength < 0) {
            throw new InvalidArgumentException('historyLength must not be negative.', 1799100012);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = [];
        if ($this->contextId !== '') {
            $json['contextId'] = $this->contextId;
        }

        if ($this->status instanceof A2aTaskState) {
            $json['status'] = $this->status->value;
        }

        if ($this->pageSize !== null) {
            $json['pageSize'] = $this->pageSize;
        }

        if ($this->pageToken !== '') {
            $json['pageToken'] = $this->pageToken;
        }

        if ($this->historyLength !== null) {
            $json['historyLength'] = $this->historyLength;
        }

        if ($this->statusTimestampAfter instanceof DateTimeImmutable) {
            $json['statusTimestampAfter'] = A2aTimestamp::format($this->statusTimestampAfter);
        }

        if ($this->includeArtifacts !== null) {
            $json['includeArtifacts'] = $this->includeArtifacts;
        }

        return $json;
    }
}
