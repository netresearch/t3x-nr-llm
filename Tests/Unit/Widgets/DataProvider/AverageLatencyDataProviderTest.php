<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Widgets\DataProvider\AverageLatencyDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(AverageLatencyDataProvider::class)]
final class AverageLatencyDataProviderTest extends AbstractUnitTestCase
{
    #[Test]
    public function returnsTheAverageLatencyFromTheRepository(): void
    {
        $repository = new InMemoryTelemetryRepository();
        $repository->averageLatencyMsReturns = 842;

        $provider = new AverageLatencyDataProvider($repository, 7);

        self::assertSame(842, $provider->getNumber());
    }

    #[Test]
    public function returnsZeroWhenNoTelemetry(): void
    {
        $provider = new AverageLatencyDataProvider(new InMemoryTelemetryRepository());

        self::assertSame(0, $provider->getNumber());
    }
}
