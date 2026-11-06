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
 * and the refusal before approval run with the workspace aspect pinned to the
 * acting user's workspace, and the tool's own refusal is asked before
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
     * The write, run with TYPO3's workspace aspect pinned to the acting
     * user's workspace (ADR-266).
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return $this->inTheActingUsersWorkspace($context, fn (): ToolResult => $this->executeInItsWorkspace($arguments, $context));
    }

    /**
     * The card, read with the workspace aspect pinned like the write.
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
     * The refusal before approval, asked with the workspace aspect pinned
     * like the write.
     *
     * @param array<string, mixed> $arguments
     */
    public function refusalBeforeApproval(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        return $this->inTheActingUsersWorkspace($context, fn (): ?ToolResult => $this->refusalBeforeApprovalInItsWorkspace($arguments, $context));
    }

    /**
     * Runs `$work` with the `workspace` aspect of TYPO3's Context set to the
     * acting user's workspace, and puts the ambient one back afterwards.
     *
     * Core's site lookup reads the rootline through that aspect, and so do
     * the DataHandler's copy of an element's translations and the tool's own
     * site lookups. Left ambient, it is the approving request's workspace in
     * the backend and live in a worker, so the same call would copy, count and
     * translate differently by where it runs. Pinned, it is what an editor
     * working in that workspace gets. Without an acting user nothing is
     * pinned; the call ends in the neutral refusal.
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

        $aspects = GeneralUtility::makeInstance(Context::class);
        $ambient = $aspects->getAspect('workspace');
        $aspects->setAspect('workspace', new WorkspaceAspect(max(0, $user->workspace)));

        try {
            return $work();
        } finally {
            $aspects->setAspect('workspace', $ambient);
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
