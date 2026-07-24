<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Widgets\DataProvider\RequestSuccessRateDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RequestSuccessRateDataProvider::class)]
final class RequestSuccessRateDataProviderTest extends AbstractUnitTestCase
{
    #[Test]
    public function returnsThePercentFromTheRepository(): void
    {
        $repository = new InMemoryTelemetryRepository();
        $repository->successRatePercentReturns = 97;

        $provider = new RequestSuccessRateDataProvider($repository, 7);

        self::assertSame(97, $provider->getNumber());
    }

    #[Test]
    public function returnsZeroWhenNoTelemetry(): void
    {
        $provider = new RequestSuccessRateDataProvider(new InMemoryTelemetryRepository());

        self::assertSame(0, $provider->getNumber());
    }
}
