<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerHeartbeatListener;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(WorkerHeartbeatListener::class)]
final class WorkerOperationsWiringTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function realWorkerIdleLifecycleReachesContainerListenersAndStops(): void
    {
        $dispatcher = $this->get(EventDispatcherInterface::class);
        $pool = $this->get(ConnectionPool::class);
        $repository = new WorkerOperationsRepository($pool);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $receiver = $this->createMock(ReceiverInterface::class);
        $receiver
            ->expects(self::once())
            ->method('get')
            ->willReturnCallback(
                function () use ($repository): array {
                    self::assertSame(
                        1,
                        $repository->snapshot(time())->liveWorkers,
                    );
                    return [];
                },
            );
        $stoppingDispatcher = new class ($dispatcher) implements EventDispatcherInterface {
            public function __construct(
                private readonly EventDispatcherInterface $delegate,
            ) {}

            public function dispatch(object $event): object
            {
                $result = $this->delegate->dispatch($event);
                if ($event instanceof WorkerRunningEvent) {
                    $event->getWorker()->stop();
                }

                return $result;
            }
        };
        $worker = new Worker(['doctrine' => $receiver], $bus, $stoppingDispatcher);
        $worker->run(['sleep' => 0]);
        self::assertSame(0, $repository->snapshot(time())->liveWorkers);
        $workerA = new Worker(['doctrine' => $receiver], $bus, $dispatcher);
        $workerB = new Worker(['doctrine' => $receiver], $bus, $dispatcher);
        $dispatcher->dispatch(new WorkerRunningEvent($workerA, true));
        $dispatcher->dispatch(new WorkerRunningEvent($workerB, true));
        self::assertSame(2, $repository->snapshot(time())->liveWorkers);
        $dispatcher->dispatch(new WorkerStoppedEvent($workerA));
        $dispatcher->dispatch(new WorkerStoppedEvent($workerB));
        self::assertSame(0, $repository->snapshot(time())->liveWorkers);
    }

    #[Test]
    public function workerRestrictedToAnotherQueueCannotClaimDefaultQueueHealth(): void
    {
        $dispatcher = $this->get(EventDispatcherInterface::class);
        $worker = new Worker(
            ['doctrine' => self::createStub(ReceiverInterface::class)],
            self::createStub(MessageBusInterface::class),
            $dispatcher,
        );
        $worker->getMetadata()->set(['queueNames' => ['other']]);
        $dispatcher->dispatch(new WorkerRunningEvent($worker, true));
        $repository = new WorkerOperationsRepository($this->get(ConnectionPool::class));
        self::assertSame(0, $repository->snapshot(time())->liveWorkers);
    }

    #[Test]
    public function idleEventsRefreshAtThirtySecondsAndPruneExpiredProcessEntries(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $repository = new WorkerOperationsRepository($pool);
        $clock = $this->createMock(ClockInterface::class);
        $clock
            ->expects(self::exactly(3))
            ->method('now')
            ->willReturn(
                new DateTimeImmutable('@100000'),
                new DateTimeImmutable('@100010'),
                new DateTimeImmutable('@100030'),
            );
        $listener = new WorkerHeartbeatListener($repository, $clock);
        $repository->touch('expired', 'doctrine', 1);
        $worker = new Worker(
            ['doctrine' => self::createStub(ReceiverInterface::class)],
            self::createStub(MessageBusInterface::class),
        );
        $listener->onRunning(new WorkerRunningEvent($worker, true));
        $connection = $pool->getConnectionForTable('tx_nrllm_worker_heartbeat');
        self::assertSame(1, $repository->snapshot(100000)->liveWorkers);
        self::assertSame(
            1,
            (int)$connection->count('*', 'tx_nrllm_worker_heartbeat', []),
        );
        $listener->onRunning(new WorkerRunningEvent($worker, true));
        self::assertSame(
            100000,
            (int)$connection
                ->select(['last_seen'], 'tx_nrllm_worker_heartbeat', [])
                ->fetchOne(),
        );
        $listener->onRunning(new WorkerRunningEvent($worker, true));
        self::assertSame(
            100030,
            (int)$connection
                ->select(['last_seen'], 'tx_nrllm_worker_heartbeat', [])
                ->fetchOne(),
        );
        $listener->onStopped(new WorkerStoppedEvent($worker));
        self::assertSame(
            0,
            (int)$connection->count('*', 'tx_nrllm_worker_heartbeat', []),
        );
    }

    #[Test]
    #[DataProvider('persistenceFailureStages')]
    public function telemetryFailureCannotInterruptRealWorkerMessageConsumption(
        int $failAt,
        bool $loggerThrows,
    ): void {
        $logger = self::createStub(LoggerInterface::class);
        $logger
            ->method('warning')
            ->willReturnCallback(
                static function () use ($loggerThrows): void {
                    if ($loggerThrows) {
                        throw new RuntimeException(
                            'diagnostic sink unavailable',
                            1791569410,
                        );
                    }
                },
            );
        $realPool = $this->get(ConnectionPool::class);
        $pool = $this->createMock(ConnectionPool::class);
        $attempts = 0;
        $pool
            ->method('getConnectionForTable')
            ->willReturnCallback(
                static function (
                    string $table,
                ) use ($realPool, &$attempts, $failAt) {
                    if ($table === 'tx_nrllm_worker_heartbeat' && ++$attempts === $failAt) {
                        throw new RuntimeException(
                            'telemetry connection unavailable',
                            1791569411,
                        );
                    }

                    return $realPool->getConnectionForTable($table);
                },
            );
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('@100000'));
        $listener = new WorkerHeartbeatListener(
            new WorkerOperationsRepository($pool),
            $clock,
            $logger,
        );
        $dispatcher = new class ($listener) implements EventDispatcherInterface {
            public function __construct(
                private readonly WorkerHeartbeatListener $listener,
            ) {}

            public function dispatch(object $event): object
            {
                if ($event instanceof WorkerStartedEvent) {
                    $this->listener->onStarted($event);
                } elseif ($event instanceof WorkerRunningEvent) {
                    $this->listener->onRunning($event);
                    $event->getWorker()->stop();
                } elseif ($event instanceof WorkerStoppedEvent) {
                    $this->listener->onStopped($event);
                }

                return $event;
            }
        };
        $message = new stdClass();
        $processed = false;
        $acked = false;
        $bus = self::createStub(MessageBusInterface::class);
        $bus
            ->method('dispatch')
            ->willReturnCallback(
                static function (
                    object $envelope,
                ) use (&$processed, $message): Envelope {
                    self::assertInstanceOf(Envelope::class, $envelope);
                    self::assertSame($message, $envelope->getMessage());
                    $processed = true;
                    return $envelope;
                },
            );
        $receiver = self::createStub(ReceiverInterface::class);
        $receiver->method('get')->willReturn([new Envelope($message)]);
        $receiver
            ->method('ack')
            ->willReturnCallback(
                static function () use (&$acked): void {
                    $acked = true;
                },
            );
        $worker = new Worker(['doctrine' => $receiver], $bus, $dispatcher);
        $error = null;
        try {
            $worker->run(['sleep' => 0]);
        } catch (RuntimeException $e) {
            $error = $e;
        }

        self::assertNull(
            $error,
            'A telemetry failure must not escape the real Messenger Worker lifecycle',
        );
        self::assertTrue(
            $processed,
            'The healthy bus must process its queued message',
        );
        self::assertTrue(
            $acked,
            'The healthy queue must acknowledge its queued message',
        );
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function persistenceFailureStages(): iterable
    {
        yield 'touch' => [1, false];
        yield 'prune' => [2, false];
        yield 'stop removal' => [3, false];
        yield 'touch and logger' => [1, true];
        yield 'prune and logger' => [2, true];
        yield 'stop removal and logger' => [3, true];
    }

    #[Test]
    #[DataProvider('partialHeartbeatFailureStages')]
    public function failedHeartbeatsRetryAfterThirtySecondsWithOneProcessIdentity(
        int $failAt,
    ): void {
        $realPool = $this->get(ConnectionPool::class);
        $pool = $this->createMock(ConnectionPool::class);
        $attempts = 0;
        $pool
            ->method('getConnectionForTable')
            ->willReturnCallback(
                static function (
                    string $table,
                ) use ($realPool, &$attempts, $failAt) {
                    if ($table === 'tx_nrllm_worker_heartbeat' && ++$attempts === $failAt) {
                        throw new RuntimeException(
                            'telemetry connection unavailable',
                            1791569412,
                        );
                    }

                    return $realPool->getConnectionForTable($table);
                },
            );
        $now = 0;
        $clock = self::createStub(ClockInterface::class);
        $clock
            ->method('now')
            ->willReturnCallback(
                static function () use (&$now): DateTimeImmutable {
                    return new DateTimeImmutable('@' . $now);
                },
            );
        $listener = new WorkerHeartbeatListener(
            new WorkerOperationsRepository($pool),
            $clock,
        );
        $worker = new Worker(
            [
                'doctrine' => self::createStub(ReceiverInterface::class),
                'other' => self::createStub(ReceiverInterface::class),
            ],
            self::createStub(MessageBusInterface::class),
        );
        // Continue examining identity and retry behavior even when the old listener throws.
        try {
            $listener->onStarted(new WorkerStartedEvent($worker));
        } catch (RuntimeException) {
        }

        $attemptsAfterFailure = $attempts;
        $now = 10;
        $listener->onRunning(new WorkerRunningEvent($worker, true));
        self::assertSame(
            $attemptsAfterFailure,
            $attempts,
            'A failed attempt must throttle subsequent idle events, including epoch zero',
        );
        $now = 30;
        $listener->onRunning(new WorkerRunningEvent($worker, true));
        self::assertSame($attemptsAfterFailure + 3, $attempts);
        $connection = $realPool->getConnectionForTable('tx_nrllm_worker_heartbeat');
        $rows = $connection
            ->select(
                ['worker_id', 'transport', 'last_seen'],
                'tx_nrllm_worker_heartbeat',
                [],
            )
            ->fetchAllAssociative();
        self::assertCount(
            2,
            $rows,
            'Partial writes must not leave phantom duplicate processes after retry',
        );
        self::assertCount(1, array_unique(array_column($rows, 'worker_id')));
        self::assertSame(
            [30, 30],
            array_map(
                static fn(array $row): int => (int)$row['last_seen'],
                $rows,
            ),
        );
        $listener->onStopped(new WorkerStoppedEvent($worker));
        self::assertSame(
            0,
            (int)$connection->count('*', 'tx_nrllm_worker_heartbeat', []),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function partialHeartbeatFailureStages(): iterable
    {
        yield 'initial touch' => [1];
        yield 'second transport after partial write' => [2];
        yield 'prune after both writes' => [3];
    }

    #[Test]
    public function telemetryDiagnosticsAreSanitizedAndFailedStopReleasesIdentity(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $pool
            ->expects(self::exactly(2))
            ->method('getConnectionForTable')
            ->with('tx_nrllm_worker_heartbeat')
            ->willThrowException(
                new RuntimeException(
                    'mysql://admin:credential@private-host/database?token=sensitive',
                ),
            );
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('@100000'));
        $records = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->method('warning')
            ->willReturnCallback(
                static function (
                    string $message,
                    array $context,
                ) use (&$records): void {
                    $records[] = [$message, $context];
                },
            );
        $listener = new WorkerHeartbeatListener(
            new WorkerOperationsRepository($pool),
            $clock,
            $logger,
        );
        $worker = new Worker(
            ['doctrine' => self::createStub(ReceiverInterface::class)],
            self::createStub(MessageBusInterface::class),
        );
        foreach ([
            new WorkerStartedEvent($worker),
            new WorkerStoppedEvent($worker),
            new WorkerStoppedEvent($worker),
        ] as $event) {
            try {
                if ($event instanceof WorkerStartedEvent) {
                    $listener->onStarted($event);
                } else {
                    $listener->onStopped($event);
                }
            } catch (RuntimeException) {
                // The assertions below expose both absent diagnostics and retained entries in the old listener.
            }
        }

        self::assertSame(
            [
                [
                    'Worker heartbeat telemetry could not be persisted',
                    [
                        'operation' => 'heartbeat',
                        'exception_class' => RuntimeException::class,
                    ],
                ],
                [
                    'Worker heartbeat telemetry could not be persisted',
                    [
                        'operation' => 'remove',
                        'exception_class' => RuntimeException::class,
                    ],
                ],
            ],
            $records,
            'Only fixed text, the operation and exception class may be logged; never DSN, exception message or object',
        );
    }
}
