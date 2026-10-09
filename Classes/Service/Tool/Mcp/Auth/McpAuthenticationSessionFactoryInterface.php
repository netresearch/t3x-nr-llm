<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrVault\Http\CancellationSignalInterface;

/**
 * @internal Open operation-bound execution or discovery credential sessions.
 */
interface McpAuthenticationSessionFactoryInterface
{
    public function openForExecution(
        McpServerRecord $server,
        ?AiActorContext $actor,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation = null,
    ): ?McpCredentialSessionInterface;

    public function openForDiscovery(
        McpServerRecord $server,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation = null,
    ): ?McpCredentialSessionInterface;
}
