<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;

/**
 * The domain handlers a publication profile may name (spec 051 A2A-SV4):
 * those registered and available in this installation.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aDomainHandlerItems
{
    public function __construct(
        private A2aDomainHandlerRegistry $handlers,
    ) {}

    /**
     * @param array{items: array<int, array{label: string, value: string}>} $params
     */
    public function addItems(array &$params): void
    {
        foreach ($this->handlers->identifiers() as $identifier) {
            $params['items'][] = ['label' => $identifier, 'value' => $identifier];
        }
    }
}
