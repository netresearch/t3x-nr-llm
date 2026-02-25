<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider;

use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for ProviderAdapterRegistry DI configuration.
 *
 * These tests verify that the service container correctly wires up
 * the ProviderAdapterRegistry with its PSR-17 dependencies.
 */
#[CoversClass(ProviderAdapterRegistry::class)]
final class ProviderAdapterRegistryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function canBeInstantiatedFromContainer(): void
    {
        // This will fail if PSR-17 factories are not properly configured
        $registry = $this->get(ProviderAdapterRegistry::class);

        self::assertInstanceOf(ProviderAdapterRegistry::class, $registry);
    }

    #[Test]
    public function registryIsSingleton(): void
    {
        $registry1 = $this->get(ProviderAdapterRegistry::class);
        $registry2 = $this->get(ProviderAdapterRegistry::class);

        self::assertSame($registry1, $registry2);
    }
}
