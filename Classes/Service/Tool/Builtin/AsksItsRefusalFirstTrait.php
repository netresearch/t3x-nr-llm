<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The entry points of a structural writer (ADR-266). `execute()`, the card
 * and the refusal before approval run in the acting user's workspace
 * ({@see self::inTheActingUsersWorkspace()}), and the tool's own refusal is asked before
 * anything else, so a call the approval scan refused writes nothing and its
 * card names the refusal instead of describing a write that will not happen.
 * One copy, so neither can drift between the tools.
 *
 * Needs {@see \Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface},
 * {@see PlansOneEditorialWriteTrait}, a `NOT_PERMITTED` constant and the
 * private `executeInItsWorkspace()`, `previewCallInItsWorkspace()` and
 * `refusalBeforeApprovalInItsWorkspace()` in the using class.
 */
trait AsksItsRefusalFirstTrait
{
    /**
     * The write, run in the acting user's workspace (ADR-266).
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return $this->inTheActingUsersWorkspace($context, fn (): ToolResult => $this->executeInItsWorkspace($arguments, $context));
    }

    /**
     * The card, read in the acting user's workspace, like the write.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        return $this->inTheActingUsersWorkspace($context, fn (): array => $this->previewCallInItsWorkspace($arguments, $context));
    }

    /**
     * The refusal before approval, asked in the acting user's workspace,
     * like the write.
     *
     * @param array<string, mixed> $arguments
     */
    public function refusalBeforeApproval(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        return $this->inTheActingUsersWorkspace($context, fn (): ?ToolResult => $this->refusalBeforeApprovalInItsWorkspace($arguments, $context));
    }

    /**
     * Runs `$work` in the acting user's workspace: the `workspace` aspect of
     * TYPO3's Context and the workspace of the global backend user are set to
     * it, and put back afterwards.
     *
     * Core reads both around and inside the DataHandler: the site lookup reads
     * the rootline through the aspect; `workspaceOL()` and, in TYPO3 13.4, the
     * page translations a copied element's translation needs read the global
     * user's workspace. Left ambient, they are the approving editor's in a
     * backend request and something else in a worker, so the same call would
     * copy, count and translate differently by where it runs. The global user
     * itself stays the request's — its session is where an installation's
     * hooks put their messages — and is never created: a process without one
     * keeps the refusal of
     * {@see WritesThroughDataHandlerTrait::refuseWithoutBackendEnvironment()}.
     * Without an acting user nothing is set; the call ends in the neutral
     * refusal.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function inTheActingUsersWorkspace(ToolExecutionContext $context, Closure $work): mixed
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return $work();
        }

        $aspects      = GeneralUtility::makeInstance(Context::class);
        $ambientSpace = $aspects->getAspect('workspace');
        $aspects->setAspect('workspace', new WorkspaceAspect(max(0, $user->workspace)));

        $globalUser = $GLOBALS['BE_USER'] ?? null;
        $global     = $globalUser instanceof BackendUserAuthentication && $globalUser !== $user ? $globalUser : null;
        $globalWas  = $global instanceof BackendUserAuthentication ? [$global->workspace, $global->workspaceRec] : null;
        if ($global instanceof BackendUserAuthentication) {
            $global->workspace    = $user->workspace;
            $global->workspaceRec = $user->workspaceRec;
        }

        try {
            return $work();
        } finally {
            if ($global instanceof BackendUserAuthentication && $globalWas !== null) {
                [$global->workspace, $global->workspaceRec] = $globalWas;
            }

            $aspects->setAspect('workspace', $ambientSpace);
        }
    }

    /**
     * The user `execute()` writes as, or the result that ends the call: the
     * refusal first, as the approval scan asked it, then the guard of
     * {@see PlansOneEditorialWriteTrait::writableActingUserInItsWorkspace()}.
     *
     * @param array<string, mixed> $arguments
     * @param non-empty-string     $table     the table the writer writes, whose TCA also proves a TCA is loaded at all
     */
    private function actingUserForTheWrite(array $arguments, ToolExecutionContext $context, string $table): BackendUserAuthentication|ToolResult
    {
        $before = $this->refusalBeforeApproval($arguments, $context);
        if ($before instanceof ToolResult) {
            return $before;
        }

        return $this->writableActingUserInItsWorkspace($context, $table);
    }

    /**
     * The user `previewCall()` describes the write for, or the lines the card
     * shows instead: the neutral refusal without an acting user, the refusal
     * before approval whatever the approver decides.
     *
     * @param array<string, mixed> $arguments
     *
     * @return BackendUserAuthentication|list<string>
     */
    private function actingUserForTheCard(array $arguments, ToolExecutionContext $context): BackendUserAuthentication|array
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [self::NOT_PERMITTED];
        }

        $before = $this->refusalBeforeApproval($arguments, $context);

        return $before instanceof ToolResult ? [$before->content] : $user;
    }
}
