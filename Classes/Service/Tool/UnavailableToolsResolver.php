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
 * One explain() call for all of them, so the enabled set and the
 * configuration's allow-list are resolved once, not once per tool.
 *
 * Remote tools are left out for anyone but an administrator. Their names come
 * from operator configuration — an MCP server and the tools it exposes — and
 * that is not a policy fact an editor needs to see; an administrator does
 * need it, to tell a tool the gate holds back from one that is missing.
 */
final readonly class UnavailableToolsResolver implements UnavailableToolsResolverInterface
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolCallPolicyInterface $policy,
    ) {}

    public function unavailable(LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        $showRemote = $user instanceof BackendUserAuthentication && $user->isAdmin();

        return array_values(array_filter(
            $this->policy->explain($this->registry->names(), $configuration, $user),
            fn(ToolPolicyDecision $decision): bool => !$decision->allowed
                && ($showRemote || !$this->registry->get($decision->toolName) instanceof RemoteToolInterface),
        ));
    }
}
