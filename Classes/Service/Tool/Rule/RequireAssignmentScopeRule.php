<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Rule;

use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\PreApprovalInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeChecker;
use Netresearch\NrLlm\Service\Tool\Scope\EffectiveAssignmentScope;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWriteInterface;
use Netresearch\NrLlm\Service\Tool\ToolApprovalRule;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Refuses a write that is not inside the scope of the run's assignment
 * (ADR-295). The first of two layers: it reads what the tool says it will
 * touch, before the call; the DataHandler hook then checks what the call
 * writes.
 *
 * The scope is the one the starter bound the run to, capped by the
 * configuration as it is now ({@see EffectiveAssignmentScope}). A configuration
 * that requires a scope and a run without one has the scope that admits
 * nothing, so every write is refused with the reason `scope_required`. A run
 * with a scope is held to it whether or not the configuration requires one.
 *
 * What counts as a write is the tool's declared effect, or a tool that needs
 * approval without declaring one, as for {@see RequireDraftWorkspaceRule}. A
 * tool that declares itself a read passes even where it asks for approval. A write tool that cannot state its targets
 * ({@see ScopedWriteInterface}) is refused with `scope_unresolvable`, and a
 * tool that changes things outside TYPO3 (an MCP server, a remote agent) with
 * `scope_remote_write`: neither can be held to a scope. A claim that writes
 * files (`ScopedWrite::$writesFiles`) is refused with `scope_files_write`,
 * because the call's rollback cannot take a file back.
 *
 * Nothing in the call's arguments reaches the scope: the rule takes it from
 * the run and reads the arguments only as the claim to check.
 *
 * @internal
 */
final readonly class RequireAssignmentScopeRule implements ToolInvocationRuleInterface, PreApprovalInvocationRuleInterface
{
    public const IDENTIFIER = 'assignment_scope';

    public const REASON_REQUIRED = 'scope_required';

    public const REASON_REMOTE = 'scope_remote_write';

    public const REASON_FILES = 'scope_files_write';

    public const REASON_UNRESOLVABLE = 'scope_unresolvable';

    private ToolEffectResolver $effects;

    public function __construct(
        private ToolRegistry $registry,
        private ApprovalPreviewTranslator $translator,
        private AssignmentScopeChecker $checker = new AssignmentScopeChecker(),
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
        $scope = EffectiveAssignmentScope::of($context->configuration, $context->execution->actor);
        if (!$scope instanceof AssignmentScope) {
            return ToolInvocationDecision::allow();
        }

        $tool = $this->registry->get($context->toolName);
        if (!$this->effects->effectFor($context->toolName)->isWrite() && ($this->effects->declaresRead($context->toolName) || !ToolApprovalRule::requiresApproval($tool))) {
            return ToolInvocationDecision::allow();
        }

        $user = $context->execution->actingBackendUser();
        if (EffectiveAssignmentScope::isMissing($context->configuration, $context->execution->actor)) {
            return ToolInvocationDecision::deny(self::REASON_REQUIRED, $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeMissing));
        }

        if ($tool instanceof RemoteToolInterface || $tool instanceof RemoteApprovalInterface) {
            return ToolInvocationDecision::deny(self::REASON_REMOTE, $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeRemote));
        }

        $write = $tool instanceof ScopedWriteInterface ? $tool->scopedWrite($context->arguments) : null;
        if (!$write instanceof ScopedWrite) {
            return ToolInvocationDecision::deny(
                self::REASON_UNRESOLVABLE,
                $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeUnresolvable, $scope->describe()),
            );
        }

        if ($write->writesFiles) {
            return ToolInvocationDecision::deny(self::REASON_FILES, $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeRemote));
        }

        $refusals = $this->checker->refusalsFor($scope, $write, EffectiveAssignmentScope::workspaceOf($context->execution->actor, $user));
        if ($refusals === []) {
            return ToolInvocationDecision::allow();
        }

        return ToolInvocationDecision::deny($refusals[0]->reason, $this->explanation($user, $scope, $refusals[0]), $refusals);
    }

    private function explanation(?BackendUserAuthentication $user, AssignmentScope $scope, ScopeRefusal $refusal): string
    {
        return match ($refusal->reason) {
            ScopeRefusal::TABLE_NOT_ALLOWED     => $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeTable, $scope->describe(), $refusal->table),
            ScopeRefusal::LANGUAGE_NOT_ALLOWED  => $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeLanguage, $scope->describe()),
            ScopeRefusal::SUBTREE_OUTSIDE_SCOPE => $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeSubtree, $scope->describe(), $refusal->subject()),
            ScopeRefusal::UNRESOLVABLE          => $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeUnresolvable, $scope->describe()),
            default                             => $this->translator->text($user, ApprovalPreviewLabel::RefusedScopeOutside, $scope->describe(), $refusal->subject()),
        };
    }
}
