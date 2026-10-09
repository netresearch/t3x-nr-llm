<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsReaderInterface;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal Operational status and explicit health thresholds (ADR-219).
 */
#[AsCommand(
    name: 'nrllm:agent:status',
    description: 'Show queue, lease and worker health aggregates.',
)]
final class AgentStatusCommand extends Command
{
    public function __construct(
        private readonly WorkerOperationsReaderInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Output aggregate JSON.',
        );
        $this->addOption(
            'max-queue-wait',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum known queue wait in seconds.',
            '300',
        );
        $this->addOption(
            'minimum-workers',
            null,
            InputOption::VALUE_REQUIRED,
            'Minimum live consumers for the transport.',
            '1',
        );
        $this->addOption(
            'worker-max-age',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum heartbeat age in seconds.',
            '120',
        );
        $this->addOption(
            'transport',
            null,
            InputOption::VALUE_REQUIRED,
            'Transport whose default-queue consumers are counted.',
            'doctrine',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $wait = $this->nonNegativeInteger($input->getOption('max-queue-wait'));
        $minimum = $this->nonNegativeInteger($input->getOption('minimum-workers'));
        $age = $this->nonNegativeInteger($input->getOption('worker-max-age'));
        $transport = $input->getOption('transport');
        if ($wait === null || $minimum === null || $age === null || $age === 0 || !is_string($transport) || preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $transport) !== 1) {
            (new SymfonyStyle($input, $output))->error(
                'Limits must be non-negative integers, heartbeat age must be positive, and transport must be a simple identifier.',
            );
            return Command::INVALID;
        }

        $snapshot = $this->repository->snapshot(time(), $age, $transport);
        $healthy = $this->isHealthy($snapshot, $wait, $minimum);
        $data = $snapshot->toArray() + ['healthy' => $healthy];
        if ($input->getOption('json') === true) {
            $output->writeln(
                json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
        } else {
            $rows = [];
            foreach ($data as $key => $value) {
                $rows[] = [$key, $this->displayValue($value)];
            }

            (new SymfonyStyle($input, $output))->table(
                ['Measurement', 'Value'],
                $rows,
            );
        }

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $parsed = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        return is_int($parsed) ? $parsed : null;
    }

    private function isHealthy(
        WorkerOperationsSnapshot $snapshot,
        int $wait,
        int $minimum,
    ): bool {
        return $snapshot->expiredLeases === 0 && $snapshot->unknownQueueWait === 0 && ($snapshot->oldestQueueWaitSeconds === null || $snapshot->oldestQueueWaitSeconds <= $wait) && $snapshot->liveWorkers >= $minimum;
    }

    private function displayValue(int|bool|null $value): string
    {
        if ($value === null) {
            return 'unknown';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return (string)$value;
    }
}
