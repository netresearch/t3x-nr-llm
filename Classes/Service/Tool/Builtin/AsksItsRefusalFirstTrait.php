<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The opening both entry points of a structural writer share (ADR-266): the
 * tool's own refusal before approval is asked before anything else, so a call
 * the approval scan refused writes nothing and its card names the refusal
 * instead of describing a write that will not happen. One copy, so the order
 * cannot drift between the tools.
 *
 * Needs {@see \Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface},
 * {@see PlansOneEditorialWriteTrait} and a `NOT_PERMITTED` constant in the
 * using class.
 */
trait AsksItsRefusalFirstTrait
{
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
