<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;

/**
 * Data provider for the "runs awaiting approval" NumberWithIcon widget.
 *
 * A live gauge — no time window: an agent run suspended WAITING_FOR_APPROVAL
 * (ADR-084) from last week still needs a human decision now. Served by the
 * status_lookup index on tx_nrllm_agentrun.
 */
final readonly class RunsAwaitingApprovalDataProvider implements NumberWithIconDataProviderInterface
{
    public function __construct(
        private AgentRunRepositoryInterface $repository,
    ) {}

    public function getNumber(): int
    {
        return $this->repository->countInStatus(AgentRunStatus::WAITING_FOR_APPROVAL);
    }
}
