<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Command;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;

/**
 * What the A2A commands share (spec 051 A2A-C9): the record lookup, the local
 * actor, and the vault scope.
 *
 * On the command line the local actor is `worker.technicalBeUserUid`: the
 * credential is read as that user (ADR-234), and the journal records it as
 * the owner of every intent the commands create. Without one configured the
 * commands refuse, because an intent without an owner would be nobody's.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aCommandContext
{
    public function __construct(
        private A2aRemoteAgentRepository $agents,
        private WorkerVaultScope $vaultScope,
    ) {}

    /**
     * @throws A2aException
     */
    public function agent(string $reference): A2aRemoteAgent
    {
        return $this->agents->findByReference($reference)
            ?? throw new A2aException(sprintf('No A2A remote agent "%s" exists.', $reference), 1799100500);
    }

    /**
     * @throws A2aException
     */
    public function owner(): int
    {
        $uid = $this->vaultScope->technicalUserUid();
        if ($uid < 1) {
            throw new A2aException(
                'The A2A commands act as the backend user in the extension setting worker.technicalBeUserUid, which is not set.',
                1799100501,
            );
        }

        return $uid;
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function asWorker(Closure $work): mixed
    {
        return $this->vaultScope->call($work);
    }
}
