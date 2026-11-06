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
 * The refusals a draft writer of ONE table gives in a draft workspace
 * whatever the approver decides, before and instead of the approval card
 * (ADR-260, ADR-262, ADR-264): `create_content_element_draft` and
 * `create_page_draft`.
 *
 * The consuming class must declare a `TABLE` constant, hold an
 * `ApprovalPreviewTranslator` in `$this->translator`, and use
 * {@see WritesThroughDataHandlerTrait} and {@see PlansOneEditorialWriteTrait}.
 */
trait DraftsOneRecordOfItsTableTrait
{
    /**
     * Where an installation takes the versioning off the table — or, on
     * TYPO3 13.4, sets `versioningWS_alwaysAllowLiveEdit` on it — TYPO3
     * keeps no draft, and in a draft workspace the call is refused before
     * the approval card. The loop asks this, and so does `execute()` first.
     *
     * @param array<string, mixed> $arguments
     */
    public function refusalBeforeApproval(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        return $this->refuseUnversionedTableInDraftWorkspace($context, self::TABLE);
    }

    /**
     * The user `execute()` writes as, or the refusal: the one before the
     * approval first, then the questions every writer opens with.
     *
     * @param array<string, mixed> $arguments
     */
    private function draftWriter(array $arguments, ToolExecutionContext $context): BackendUserAuthentication|ToolResult
    {
        return $this->refusalBeforeApproval($arguments, $context)
            ?? $this->writableActingUserInItsWorkspace($context, self::TABLE);
    }

    /**
     * The line that stands for the card where the write is refused whatever
     * the approver decides, or null: the table keeps no draft (in the card's
     * language), or the run did not record its workspace — the card must not
     * promise a draft that is never stored.
     *
     * @param array<string, mixed> $arguments
     */
    private function draftCardRefusal(array $arguments, ToolExecutionContext $context, BackendUserAuthentication $user): ?string
    {
        if ($this->refusalBeforeApproval($arguments, $context) instanceof ToolResult) {
            [$t, $q] = $this->translator->boundTo($user, $this->excerpt(...));

            return $this->unversionedTableLine($t, $q, $user, $this->translator->tableLabel($user, self::TABLE));
        }

        return $this->refuseUnpinnedWorkspace($context, $user)?->content;
    }
}
