<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\AgentRunCancellationSignal;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The signal nr-vault polls while a request is on the wire (ADR-190, #774).
 *
 * Every case runs the REAL {@see AgentRunPersister} over a stubbed repository,
 * because the fail-soft property under test is the persister's and asserting it
 * against a stubbed persister would assert the stub.
 */
#[CoversClass(AgentRunCancellationSignal::class)]
final class AgentRunCancellationSignalTest extends TestCase
{
    private const RUN_UUID = 'a2f1c0de-0000-4000-8000-000000000001';

    /**
     * nr-vault asks BEFORE it reads the secret and refuses there under its own
     * audit action. A signal whose throttle window opened at construction would
     * answer false on entry and let a credential go out for a run that was
     * already cancelled.
     */
    #[Test]
    public function theFirstQuestionAlwaysReachesTheRow(): void
    {
        $reads  = 0;
        $signal = $this->signalOver($this->clock(), static function () use (&$reads): AgentRun {
            ++$reads;

            return self::runRow('cancelled');
        });

        self::assertTrue($signal->isCancelled());
        self::assertSame(1, $reads);
    }

    /**
     * Ten reads a second, per in-flight request, to observe a state an operator
     * changes by hand is a poor trade. Within the window the answer is the
     * stored one and the row is not touched.
     */
    #[Test]
    public function withinTheWindowTheRowIsReadOnce(): void
    {
        $reads = 0;
        $clock = $this->clock();
        $signal = $this->signalOver($clock, static function () use (&$reads): AgentRun {
            ++$reads;

            return self::runRow('running');
        });

        // Ten ticks at nr-vault's own rate, all inside one second.
        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse($signal->isCancelled());
            $clock->advanceSeconds(0.09);
        }

        self::assertSame(1, $reads, 'The window is a second; ten ticks inside it are one read.');
    }

    /**
     * And the window ends. A cancel recorded while the transfer runs is honoured
     * about a second later, which is the difference between aborting the call
     * and waiting out the operation deadline.
     */
    #[Test]
    public function pastTheWindowTheRowIsReadAgain(): void
    {
        $status = 'running';
        $clock  = $this->clock();
        $signal = $this->signalOver($clock, static function () use (&$status): AgentRun {
            return self::runRow($status);
        });

        self::assertFalse($signal->isCancelled());

        $status = 'cancelled';
        self::assertFalse($signal->isCancelled(), 'Still inside the window, so still the stored answer.');

        $clock->advanceSeconds(1.0);
        self::assertTrue($signal->isCancelled());
    }

    /**
     * Once true, true without asking again. A run that somehow left CANCELLED
     * cannot un-cancel a transfer that has already been torn down, and a second
     * read would only add a way to answer differently twice about one transfer.
     */
    #[Test]
    public function onceCancelledTheAnswerStandsWithoutReadingAgain(): void
    {
        $reads = 0;
        $clock = $this->clock();
        $signal = $this->signalOver($clock, static function () use (&$reads): AgentRun {
            ++$reads;

            return self::runRow('cancelled');
        });

        self::assertTrue($signal->isCancelled());

        // Far past the window, where an unthrottled read would happen.
        $clock->advanceSeconds(60.0);
        self::assertTrue($signal->isCancelled());
        self::assertSame(1, $reads);
    }

    /**
     * A store hiccup must never fabricate a cancellation -- the rule the
     * executor's own probe follows -- and must never throw either: an exception
     * here would escape mid-transfer, after the credential has gone out.
     */
    #[Test]
    public function aFailingStoreIsNotACancellation(): void
    {
        $signal = $this->signalOver($this->clock(), static function (): AgentRun {
            throw new RuntimeException('the database went away', 5913345750);
        });

        self::assertFalse($signal->isCancelled());
    }

    /**
     * An unknown run is not a cancelled one either. The uuid is the caller's,
     * and a row that is simply not there says nothing about the operator's
     * intent.
     */
    #[Test]
    public function anUnknownRunIsNotACancellation(): void
    {
        $signal = $this->signalOver($this->clock(), static fn(): ?AgentRun => null);

        self::assertFalse($signal->isCancelled());
    }

    private function clock(): FakeMcpClock
    {
        return new FakeMcpClock();
    }

    /**
     * @param callable(): ?AgentRun $findByUuid
     */
    private function signalOver(FakeMcpClock $clock, callable $findByUuid): AgentRunCancellationSignal
    {
        $repository = self::createStub(AgentRunRepositoryInterface::class);
        $repository->method('findByUuid')->willReturnCallback(
            static fn(string $uuid): ?AgentRun => $findByUuid(),
        );

        return new AgentRunCancellationSignal(
            new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL)),
            $clock,
            self::RUN_UUID,
        );
    }

    private static function runRow(string $status): AgentRun
    {
        return new AgentRun(1, self::RUN_UUID, $status, 0, '', 42, 0, false, 0, 0, 0, 0.0, '', '', 0, 0, 0);
    }
}
