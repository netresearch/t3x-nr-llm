<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Rule;

use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\PreApprovalInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolApprovalRule;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Refuses a write when the configuration requires a draft workspace and the
 * run does not work in one (ADR-293).
 *
 * "Works in one" is the workspace the run recorded when it started
 * (ADR-260) AND the acting backend user the run resolves to now being set up in
 * exactly that workspace. A user who lost access resolves to no user and is
 * refused. The test is the workspace, never the administrator flag, so an
 * administrator is refused in the live workspace like anybody else. A run that
 * recorded no workspace (queued before ADR-260) writes live only, and is
 * refused.
 *
 * What counts as a write is the tool's declared effect, as everywhere else
 * ({@see ToolEffectResolver}), or a tool that needs approval without declaring
 * an effect. Reads pass. A tool that changes things outside TYPO3's workspaces
 * (an MCP server, a remote agent) is refused in every workspace: a draft
 * workspace cannot hold what it writes.
 *
 * @internal
 */
final readonly class RequireDraftWorkspaceRule implements ToolInvocationRuleInterface, PreApprovalInvocationRuleInterface
{
    public const IDENTIFIER = 'draft_workspace_required';

    public const REASON_LIVE = 'live_workspace';

    public const REASON_GONE = 'workspace_unavailable';

    public const REASON_REMOTE = 'remote_write';

    private ToolEffectResolver $effects;

    public function __construct(
        private ToolRegistry $registry,
        private ApprovalPreviewTranslator $translator,
    ) {
        $this->effects = new ToolEffectResolver($registry);
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function requiresCompleteHistory(): bool
    {
        return false;
    }

    public function decide(
        ToolInvocationContext $context,
    ): ToolInvocationDecision {
        if (!$context->configuration->getRequireDraftWorkspace()) {
            return ToolInvocationDecision::allow();
        }

        $tool = $this->registry->get($context->toolName);
        if (!$this->effects->effectFor($context->toolName)->isWrite() && !ToolApprovalRule::requiresApproval($tool)) {
            return ToolInvocationDecision::allow();
        }

        $user = $context->execution->actingBackendUser();
        if ($tool instanceof RemoteToolInterface || $tool instanceof RemoteApprovalInterface) {
            return ToolInvocationDecision::deny(self::REASON_REMOTE, $this->translator->text($user, ApprovalPreviewLabel::RefusedRemoteWrite));
        }

        $workspace = $context->execution->actor->workspaceId ?? 0;
        if ($workspace > 0 && $user instanceof BackendUserAuthentication && $user->workspace === $workspace) {
            return ToolInvocationDecision::allow();
        }

        return $workspace > 0
            ? ToolInvocationDecision::deny(self::REASON_GONE, $this->translator->text($user, ApprovalPreviewLabel::RefusedWorkspaceGone))
            : ToolInvocationDecision::deny(self::REASON_LIVE, $this->translator->text($user, ApprovalPreviewLabel::RefusedLiveWorkspace));
    }
}
