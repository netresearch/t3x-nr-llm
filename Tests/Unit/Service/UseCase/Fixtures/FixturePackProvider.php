<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\UseCase\Fixtures;

use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrLlm\Service\UseCase\UseCasePackProviderInterface;

/**
 * Minimal in-memory UseCasePackProviderInterface double.
 *
 * Carries the packs it was constructed with, so a test controls exactly which
 * declarations the registry collects without touching the DI container.
 */
final readonly class FixturePackProvider implements UseCasePackProviderInterface
{
    /**
     * @param list<UseCasePack> $packs
     */
    public function __construct(
        private array $packs,
    ) {}

    /**
     * @return list<UseCasePack>
     */
    public function getPacks(): array
    {
        return $this->packs;
    }
}
