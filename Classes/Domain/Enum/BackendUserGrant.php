<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * A capability grant for a human backend user (ADR-130) — the grant-set the
 * ADR-117 door asked for, designed onto {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext}
 * rather than the ambient superglobal.
 *
 * Grants are assigned per backend GROUP through TYPO3's custom permission
 * options (`customPermOptions`) and frozen into the actor at the HTTP
 * boundary. Administrators hold every grant implicitly (the core `check()`
 * short-circuits on isAdmin); service accounts hold none — their mechanism
 * is {@see ServiceAccountScope}. A user without groups can hold no grant:
 * `custom_options` is a be_groups field.
 *
 * Each case maps to exactly one enforcement point — there is no wildcard
 * grant, and a case is only added TOGETHER with its consumer (a grant
 * nothing reads is worse than none).
 *
 * This docblock used to reserve `tasks_manage` for the editing module.
 * ADR-169 retired that reservation: the records a management surface would
 * own are authorised by `tables_modify` and `non_exclude_fields`, so the
 * enforcement point it was waiting for is TYPO3's, not ours. Do not add the
 * case back without naming an action those two permissions do not already
 * cover.
 *
 * The values are deliberately underscore-separated, not colon-namespaced
 * like {@see ServiceAccountScope}: TYPO3 strips `:|,` from custom
 * permission item keys when rendering the be_groups select
 * (`TcaItemsProcessorFunctions::populateCustomPermissionOptions()`), so a
 * colon value would be stored mangled and every `check()` would silently
 * deny.
 *
 * @api
 */
enum BackendUserGrant: string
{
    /**
     * Execute an existing task and refresh its input data
     * ({@see \Netresearch\NrLlm\Controller\Backend\TaskExecutionController::executeAction()},
     * {@see \Netresearch\NrLlm\Controller\Backend\TaskExecutionController::refreshInputAction()}).
     */
    case TASKS_USE = 'tasks_use';

    /**
     * Approve, deny or submit input to a run suspended for a human decision
     * ({@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::mayActOnRun()})
     * — the human sibling of {@see ServiceAccountScope::AGENT_APPROVE}.
     */
    case AGENT_APPROVE = 'agent_approve';

    /**
     * The `customPermOptions` value TYPO3 stores in `be_groups.custom_options`
     * for this grant, checked via
     * `BackendUserAuthentication::check('custom_options', ...)`.
     */
    public function permissionValue(): string
    {
        return 'tx_nrllm_grants:' . $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
