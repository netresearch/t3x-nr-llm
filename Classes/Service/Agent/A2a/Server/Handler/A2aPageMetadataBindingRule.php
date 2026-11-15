<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Domain\ValueObject\ObservedState;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Service\Tool\InvocationDigests;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;

/**
 * A write of the page metadata skill is the proposal a passed check judged,
 * on the record as that check saw it (ADR-298, spec 056): the latest `ok`
 * entry of `check_page_metadata` in the run's invocation history carries the
 * digest of exactly the write's arguments, and the digest of the state the
 * check observed equals the record's state now. A later check of another
 * variant replaces an earlier one; a failed check does not count.
 *
 * The answer depends on the history, which before the approval card does not
 * hold the calls of the same turn, so this rule is asked only when the call
 * runs (ADR-293): a check and a write the model asks for in one turn are
 * both shown, and the write is compared after the check ran. It does not
 * require a complete history: an incomplete one still holds a check made
 * since, and only a write of this skill with no check since is refused for
 * it.
 *
 * A write the contract rule refuses keeps the contract's reason, which says
 * what is wrong with the proposal itself, and a row the scope refuses keeps
 * the scope's.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPageMetadataBindingRule implements ToolInvocationRuleInterface
{
    public const IDENTIFIER = 'page_metadata_binding';

    public const REASON_NOT_CHECKED = 'proposal_not_checked';

    public const REASON_CHANGED = 'changed_since_check';

    public function __construct(
        private A2aPageMetadataContractRule $contract,
        private InvocationDigests $digests,
    ) {}

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * The policy would refuse every tool of every run on an incomplete
     * history; the rule checks completeness itself, for its own calls only.
     */
    public function requiresCompleteHistory(): bool
    {
        return false;
    }

    public function decide(ToolInvocationContext $context): ToolInvocationDecision
    {
        if (!$this->contract->holds($context) || $this->contract->scopeRefuses($context) || !$this->contract->decide($context)->allowed) {
            return ToolInvocationDecision::allow();
        }

        $request = $this->contract->requestFor($context->execution->run);
        if (!$request instanceof A2aPageMetadataRequest) {
            return ToolInvocationDecision::allow();
        }

        $check = null;
        foreach ($context->history->entries as $entry) {
            if ($entry['tool'] === A2aPageMetadataDomainHandler::CHECK_TOOL && $entry['outcome'] === 'ok') {
                $check = $entry;
            }
        }

        if ($check === null) {
            // An incomplete history may have lost an earlier check; a check
            // made since is found above.
            if (!$context->history->complete) {
                return ToolInvocationDecision::deny('history_incomplete', 'This run cannot show that the proposal was checked, so no write is allowed. Check it with check_page_metadata again. Nothing was changed.');
            }

            return ToolInvocationDecision::deny(self::REASON_NOT_CHECKED, 'Check exactly this proposal with check_page_metadata before writing it. Nothing was changed.');
        }

        $arguments = $this->digests->arguments($context->arguments);
        if ($arguments === null || ($check['arguments'] ?? null) !== $arguments) {
            return ToolInvocationDecision::deny(self::REASON_NOT_CHECKED, 'The last passed check was of other arguments. Check exactly this proposal with check_page_metadata, then write the same arguments. Nothing was changed.');
        }

        $state = $this->contract->observe($request, $context->arguments, $context->execution);
        if (!$state instanceof ObservedState || ($check['state'] ?? null) !== $state->digest) {
            return ToolInvocationDecision::deny(self::REASON_CHANGED, 'The record changed after the check. Check the proposal again with check_page_metadata. Nothing was changed.');
        }

        return ToolInvocationDecision::allow();
    }
}
