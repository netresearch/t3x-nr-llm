<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\DeriveObservedOutcomesCommand;
use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Outcome\CallOutcomeRepositoryInterface;
use Netresearch\NrLlm\Service\Outcome\ObservedOutcomeDeriver;
use Netresearch\NrLlm\Service\Outcome\ObservedWrite;
use Netresearch\NrLlm\Service\Outcome\WrittenRecordRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeriveObservedOutcomesCommand::class)]
final class DeriveObservedOutcomesCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function windows(): iterable
    {
        yield 'default' => [[], 7];
        yield 'configured whole days' => [['--days' => '12'], 12];
        yield 'invalid defaults' => [['--days' => 'invalid'], 7];
        yield 'zero is raised to floor' => [['--days' => '0'], 1];
        yield 'negative is raised to floor' => [['--days' => '-5'], 1];
        yield 'numeric fraction is truncated' => [['--days' => '2.9'], 2];
    }

    /**
     * @param array<string, string> $input
     */
    #[Test]
    #[DataProvider('windows')]
    public function daysSelectsTheObservationWindowAndAnEmptyPassSucceeds(array $input, int $days): void
    {
        $writes = $this->createMock(WrittenRecordRepositoryInterface::class);
        $outcomes = $this->createMock(CallOutcomeRepositoryInterface::class);
        $before = time();

        $writes
            ->expects(self::once())
            ->method('findUnansweredCorrelations')
            ->with(
                self::callback(
                    static function (int $timestamp) use ($before, $days): bool {
                        self::assertGreaterThanOrEqual($before - $days * 86400, $timestamp);
                        self::assertLessThanOrEqual(time() - $days * 86400, $timestamp);
                        return true;
                    },
                ),
                200,
            )
            ->willReturn([]);
        $writes->expects(self::never())->method('findWritesForCorrelation');
        $outcomes->expects(self::never())->method('record');

        $tester = new CommandTester(new DeriveObservedOutcomesCommand(new ObservedOutcomeDeriver($writes, $outcomes)));
        self::assertSame(Command::SUCCESS, $tester->execute($input));
        self::assertStringContainsString('No write has left its observation window since the last run.', $tester->getDisplay());
    }

    #[Test]
    public function nonemptyPassPrintsPerOutcomeCountsAndPersistsTheDerivedAnswers(): void
    {
        $writes = $this->createMock(WrittenRecordRepositoryInterface::class);
        $outcomes = $this->createMock(CallOutcomeRepositoryInterface::class);
        $writes->method('findUnansweredCorrelations')->willReturn(['run-one', 'run-two']);
        $writes
            ->method('findWritesForCorrelation')
            ->willReturnCallback(static fn(string $correlation): array => [new ObservedWrite($correlation, new RecordReference('pages', $correlation === 'run-one' ? 1 : 2), 100)]);
        $writes->method('historyAfter')->willReturn(['later' => [], 'oldestRetained' => 50]);
        $writes->method('recordExists')->willReturnCallback(static fn(RecordReference $record): bool => $record->uid === 1);

        $recorded = [];
        $outcomes
            ->expects(self::exactly(2))
            ->method('record')
            ->willReturnCallback(
                static function (string $correlation, CallOutcome $outcome) use (&$recorded): void {
                    $recorded[$correlation] = $outcome;
                },
            );

        $tester = new CommandTester(new DeriveObservedOutcomesCommand(new ObservedOutcomeDeriver($writes, $outcomes)));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['run-one' => CallOutcome::ACCEPTED_UNCHANGED, 'run-two' => CallOutcome::DISCARDED], $recorded);
        self::assertStringContainsString('Outcome', $tester->getDisplay());
        self::assertStringContainsString('Writes', $tester->getDisplay());
        self::assertStringContainsString('accepted_unchanged', $tester->getDisplay());
        self::assertStringContainsString('discarded', $tester->getDisplay());
        self::assertStringNotContainsString('No write has left its observation window', $tester->getDisplay());
    }
}
