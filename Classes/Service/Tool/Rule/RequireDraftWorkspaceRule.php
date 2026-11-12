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
 * (ADR-260), confirmed by the acting backend user the run resolves to now:
 * that user is set up in the recorded workspace, or has lost access to it and
 * is no user at all. The test is the workspace, never the administrator flag,
 * so an administrator is refused in the live workspace like anybody else. A run
 * that recorded no workspace (queued before ADR-260) writes live only, and is
 * refused.
 *
 * What counts as a write is the tool's declared effect, as everywhere else
 * ({@see ToolEffectResolver}). Reads, and tools that declare nothing, pass.
 *
 * @internal
 */
final readonly class RequireDraftWorkspaceRule implements ToolInvocationRuleInterface, PreApprovalInvocationRuleInterface
{
    public const IDENTIFIER = 'draft_workspace_required';

    public const REASON_LIVE = 'live_workspace';

    public const REASON_GONE = 'workspace_unavailable';

    private ToolEffectResolver $effects;

    public function __construct(
        ToolRegistry $registry,
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
        if (!$context->configuration->getRequireDraftWorkspace() || !$this->effects->effectFor($context->toolName)->isWrite()) {
            return ToolInvocationDecision::allow();
        }

        $workspace = $context->execution->actor->workspaceId ?? 0;
        $user      = $context->execution->actingBackendUser();
        if ($workspace > 0 && $user instanceof BackendUserAuthentication && $user->workspace === $workspace) {
            return ToolInvocationDecision::allow();
        }

        return $workspace > 0
            ? ToolInvocationDecision::deny(self::REASON_GONE, $this->translator->text($user, ApprovalPreviewLabel::RefusedWorkspaceGone))
            : ToolInvocationDecision::deny(self::REASON_LIVE, $this->translator->text($user, ApprovalPreviewLabel::RefusedLiveWorkspace));
    }
}
