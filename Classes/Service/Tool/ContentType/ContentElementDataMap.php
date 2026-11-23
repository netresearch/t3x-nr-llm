<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\ContentType;

/**
 * What {@see ContentElementComposer::dataMap()} answers: the datamap of one
 * content element with its child records, or the refusals that stop it.
 *
 * @api
 */
final readonly class ContentElementDataMap
{
    /**
     * @param array<string, array<string, array<string, mixed>>> $dataMap  table → placeholder → columns
     * @param list<string>                                       $refusals empty when the datamap may be written
     */
    public function __construct(
        public array $dataMap,
        public array $refusals,
    ) {}

    public static function refused(string $refusal): self
    {
        return new self([], [$refusal]);
    }

    public function isRefused(): bool
    {
        return $this->refusals !== [];
    }
}
