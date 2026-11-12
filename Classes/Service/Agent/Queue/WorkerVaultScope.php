<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Queue;

use Closure;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrVault\Exception\TechnicalActorException;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use const PHP_SAPI;

/**
 * The identity a queued run reads provider keys as on the command line (ADR-234).
 *
 * A console command boots a backend user that is not logged in, and nr_vault
 * answers such a process with its CLI rule alone. `allowCliAccess` is off by
 * default, so a worker there gets no key and every run it claims fails. The run's
 * own actor does not help: it decides what the tools may touch, and nr_vault
 * never hears of it.
 *
 * `worker.technicalBeUserUid` names the backend user whose grants on a secret
 * count for the worker. 0 leaves the process as it is, for an installation that
 * allows the CLI. A web request keeps its logged-in user whatever the setting
 * says: a synchronous run is that person's.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class WorkerVaultScope
{
    public function __construct(
        private TechnicalActorContextInterface $technicalActor,
        private ExtensionConfiguration $extensionConfiguration,
        private bool $commandLine = PHP_SAPI === 'cli',
    ) {}

    /**
     * @param Closure(): AgentRunResult $work
     *
     * @throws TechnicalActorException when the configured user cannot act
     */
    public function run(Closure $work): AgentRunResult
    {
        $uid = $this->technicalBeUserUid();
        if ($uid < 1 || !$this->commandLine) {
            return $work();
        }

        $result = $this->technicalActor->runAs($uid, $work);
        if (!$result instanceof AgentRunResult) {
            throw new RuntimeException('The worker scope returned no run result', 1798000030);
        }

        return $result;
    }

    /**
     * Any work, run as the configured technical user on the command line.
     *
     * The same rule as {@see self::run()}, for work that is not an agent run:
     * the A2A commands read the remote agent's credential as
     * `worker.technicalBeUserUid` (spec 051 A2A-C9).
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     *
     * @throws TechnicalActorException when the configured user cannot act
     */
    public function call(Closure $work): mixed
    {
        $uid = $this->technicalBeUserUid();
        if ($uid < 1 || !$this->commandLine) {
            return $work();
        }

        /** @var T */
        return $this->technicalActor->runAs($uid, $work);
    }

    /**
     * The configured technical backend user; 0 when none is configured.
     */
    public function technicalUserUid(): int
    {
        return $this->technicalBeUserUid();
    }

    private function technicalBeUserUid(): int
    {
        try {
            $configuration = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return 0;
        }

        $worker = is_array($configuration) ? ($configuration['worker'] ?? null) : null;
        $uid    = is_array($worker) ? ($worker['technicalBeUserUid'] ?? null) : null;

        return is_numeric($uid) ? (int) $uid : 0;
    }
}
