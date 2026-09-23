<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Runs the composite gate over every registered tool and keeps the refusals
 * (ADR-201).
 *
 * The candidates are the registry's names, not the globally enabled ones that
 * {@see ToolCallPolicyInterface::explain()} falls back to: a tool an
 * administrator switched off is exactly the kind a user should be told about.
 */
final readonly class UnavailableToolsResolver implements UnavailableToolsResolverInterface
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolCallPolicyInterface $policy,
    ) {}

    public function unavailable(LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        return array_values(array_filter(
            $this->policy->explain($this->registry->names(), $configuration, $user),
            static fn(ToolPolicyDecision $decision): bool => !$decision->allowed,
        ));
    }
}
