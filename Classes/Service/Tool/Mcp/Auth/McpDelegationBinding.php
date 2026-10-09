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
 * @internal Immutable actor, server and deadline binding for one operation.
 */
final readonly class McpDelegationBinding
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public McpServerRecord $server,
        public AiActorContext $actor,
        public McpDelegationProfile $profile,
        public array $scopes,
        public McpOperationDeadline $deadline,
        public ?CancellationSignalInterface $cancellation,
    ) {}
}
