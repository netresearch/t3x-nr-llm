<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

/**
 * Map the explicit actor without ambient backend or HTTP credentials.
 * Missing, disabled or insufficient grants must throw (ADR-217).
 *
 * @api
 */
interface McpSubjectResolverInterface
{
    /**
     * @param list<string> $scopes
     */
    public function resolve(
        AiActorContext $actor,
        McpDelegationProfile $profile,
        string $audience,
        array $scopes,
    ): McpSubjectCredential;
}
