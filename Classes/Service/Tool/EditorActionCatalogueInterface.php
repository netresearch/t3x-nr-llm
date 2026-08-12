<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOfferGroup;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * What an editor may do, and the run that does it (ADR-158).
 *
 * The seam between the declarations ADR-152 introduced and the surface that
 * offers them. Both methods answer for ONE viewer: the declaration set is
 * user-independent, the offer set is not, and the difference is decided by
 * {@see ToolCallPolicyInterface} and by whether that viewer may use the default
 * configuration at all (ADR-070) — never by the caller.
 *
 * The two methods are one class on purpose. {@see runRequestFor()} re-asks the
 * exact question {@see groupsFor()} answered, so a POST naming a tool the
 * catalogue never offered cannot produce a run. Split across two services,
 * that second check is the one a future entry point forgets.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface EditorActionCatalogueInterface
{
    /**
     * The editor actions `$user` may start right now, grouped by tool group.
     *
     * Narrowed to the actions that apply to `$recordTable` when one is given —
     * matched against the declaration's `recordTypes`, which names the SUBJECT
     * an editor selects rather than the table the write lands in (ADR-152).
     * An empty `$recordTable` means "everything on offer".
     *
     * Empty when no default LLM configuration exists — the gate is evaluated
     * against a configuration, and without one there is nothing to run on — and
     * equally empty when the default is restricted to backend groups this
     * viewer is not in (ADR-070).
     *
     * @return list<EditorActionOfferGroup>
     */
    public function groupsFor(?BackendUserAuthentication $user, string $recordTable = ''): array;

    /**
     * The ordinary agent run that performs one action on one record — or null
     * when this user is not offered that action for that record.
     *
     * There is no second executor and no special runtime (ADR-152): the request
     * is an {@see AgentRunRequest} like any other, restricted to the one tool,
     * and it is handed to {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::run()}.
     * The write then suspends for approval exactly as it does today.
     *
     * `$instruction` is the editor's own free text. It is bounded and framed as
     * untrusted content in the prompt; it never decides which tool runs or
     * which record it touches.
     */
    public function runRequestFor(
        string $toolName,
        string $recordTable,
        int $recordUid,
        string $instruction,
        AiActorContext $actor,
        ?BackendUserAuthentication $user,
    ): ?AgentRunRequest;
}
