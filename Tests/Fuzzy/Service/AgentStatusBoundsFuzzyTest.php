<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Service;

use Eris\Generators;
use Netresearch\NrLlm\Command\AgentStatusCommand;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsReaderInterface;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsSnapshot;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AgentStatusCommand::class)]
final class AgentStatusBoundsFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function numericOptionsAcceptOnlyTheDeclaredRangesBeforeDatabaseAccess(): void
    {
        $this
            ->forAll(
                Generators::choose(-20, 1000),
                Generators::elements(
                    ['max-queue-wait', 'minimum-workers', 'worker-max-age'],
                ),
            )
            ->then(
                function (int $value, string $option): void {
                    $reader = $this->reader();
                    $tester = new CommandTester(new AgentStatusCommand($reader));
                    $valid = $option === 'worker-max-age' ? $value > 0 : $value >= 0;
                    self::assertSame(
                        $valid ? Command::SUCCESS : Command::INVALID,
                        $tester->execute(
                            [
                                '--' . $option => (string)$value,
                                '--json' => true,
                            ],
                        ),
                    );
                    self::assertSame($valid ? 1 : 0, $reader->reads);
                },
            );
    }
    #[Test]
    public function transportNameLengthIsBoundedBeforeDatabaseAccess(): void
    {
        $this
            ->forAll(Generators::choose(0, 90))
            ->then(
                function (int $length): void {
                    $reader = $this->reader();
                    $tester = new CommandTester(new AgentStatusCommand($reader));
                    $valid = $length >= 1 && $length <= 64;
                    self::assertSame(
                        $valid ? Command::SUCCESS : Command::INVALID,
                        $tester->execute(
                            [
                                '--transport' => str_repeat('a', $length),
                                '--json' => true,
                            ],
                        ),
                    );
                    self::assertSame($valid ? 1 : 0, $reader->reads);
                },
            );
    }
    /**
     * @return WorkerOperationsReaderInterface&object{reads:int}
     */
    private function reader(): WorkerOperationsReaderInterface
    {
        return new class implements WorkerOperationsReaderInterface {
            public int $reads = 0;
            public function snapshot(
                int $now,
                int $workerMaxAge = 120,
                string $transport = 'doctrine',
            ): WorkerOperationsSnapshot {
                ++$this->reads;
                return new WorkerOperationsSnapshot(0, 0, 0, 0, null, 0, 1000);
            }
        };
    }
}
