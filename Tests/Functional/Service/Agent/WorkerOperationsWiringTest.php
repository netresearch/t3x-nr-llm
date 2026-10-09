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
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
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
}
