<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Operations;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use WeakMap;

/**
 * @internal Process liveness, independent of agent-run leases (ADR-219).
 */
final class WorkerHeartbeatListener
{
    /** @var WeakMap<Worker,array{id:string,lastSeen:int}> */
    private WeakMap $workers;

    public function __construct(
        private readonly WorkerOperationsRepository $repository,
        private readonly ClockInterface $clock,
    ) {
        $this->workers = new WeakMap();
    }

    #[AsEventListener(identifier: 'nr-llm/worker-started')]
    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->heartbeat($event->getWorker(), true);
    }

    #[AsEventListener(identifier: 'nr-llm/worker-running')]
    public function onRunning(WorkerRunningEvent $event): void
    {
        $this->heartbeat($event->getWorker(), false);
    }

    #[AsEventListener(identifier: 'nr-llm/worker-stopped')]
    public function onStopped(WorkerStoppedEvent $event): void
    {
        $worker = $event->getWorker();
        if (isset($this->workers[$worker])) {
            $this->repository->remove($this->workers[$worker]['id']);
            unset($this->workers[$worker]);
        }
    }

    private function heartbeat(Worker $worker, bool $force): void
    {
        $queues = $worker->getMetadata()->getQueueNames();
        if ($queues !== null && !in_array('default', $queues, true)) {
            $this->onStopped(new WorkerStoppedEvent($worker));
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $entry = $this->workers[$worker] ?? ['id' => bin2hex(random_bytes(16)), 'lastSeen' => 0];
        if (!$force && $entry['lastSeen'] > 0 && $now - $entry['lastSeen'] < 30) {
            return;
        }

        foreach ($worker->getMetadata()->getTransportNames() as $transport) {
            if (is_string($transport) && preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $transport) === 1) {
                $this->repository->touch($entry['id'], $transport, $now);
            }
        }

        $this->repository->prune($now - 86400);
        $this->workers[$worker] = ['id' => $entry['id'], 'lastSeen' => $now];
    }
}
