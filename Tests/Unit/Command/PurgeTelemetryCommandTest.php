<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\PurgeTelemetryCommand;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PurgeTelemetryCommand::class)]
final class PurgeTelemetryCommandTest extends TestCase
{
    #[Test]
    public function purgesWithThirtyDayDefaultWindow(): void
    {
        $repository = $this->spyRepository(deleted: 5);
        $tester     = new CommandTester(new PurgeTelemetryCommand($repository));

        $before = time();
        $exit   = $tester->execute([]);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotNull($repository->purgeCutoff);
        // Cutoff is "now minus 30 days" computed inside execute().
        self::assertGreaterThanOrEqual($before - (30 * 86400), $repository->purgeCutoff);
        self::assertLessThanOrEqual($after - (30 * 86400), $repository->purgeCutoff);
        self::assertStringContainsString('5 telemetry row(s)', $tester->getDisplay());
    }

    #[Test]
    public function honoursCustomDaysOption(): void
    {
        $repository = $this->spyRepository(deleted: 0);
        $tester     = new CommandTester(new PurgeTelemetryCommand($repository));

        $before = time();
        $exit   = $tester->execute(['--days' => '7']);
        $after  = time();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotNull($repository->purgeCutoff);
        self::assertGreaterThanOrEqual($before - (7 * 86400), $repository->purgeCutoff);
        self::assertLessThanOrEqual($after - (7 * 86400), $repository->purgeCutoff);
    }

    #[Test]
    public function rejectsNonPositiveDays(): void
    {
        $repository = $this->spyRepository(deleted: 0);
        $tester     = new CommandTester(new PurgeTelemetryCommand($repository));

        $exit = $tester->execute(['--days' => '0']);

        self::assertSame(Command::INVALID, $exit);
        self::assertNull($repository->purgeCutoff, 'No purge must run for an invalid window.');
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    private function spyRepository(int $deleted): InMemoryTelemetryRepository
    {
        $repository               = new InMemoryTelemetryRepository();
        $repository->purgeReturns = $deleted;

        return $repository;
    }
}
