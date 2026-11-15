<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;

/**
 * A write of the page metadata skill follows a successful `get_page_content`
 * earlier in the run (ADR-297, spec 055 PC4). The answer depends on the
 * invocation history, so this rule is asked only when the call runs, not
 * before its approval card (ADR-293): a read and a write the model asks for
 * in one turn are both shown, and the write runs after the read.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPageMetadataReadFirstRule implements ToolInvocationRuleInterface
{
    public const IDENTIFIER = 'page_metadata_read_first';

    public const REASON_NOT_READ = 'page_not_read';

    public function __construct(
        private A2aPageMetadataContractRule $contract,
    ) {}

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function requiresCompleteHistory(): bool
    {
        return true;
    }

    public function decide(ToolInvocationContext $context): ToolInvocationDecision
    {
        // A row the scope refuses is left to the scope, whose reason the
        // model should read.
        if (!$this->contract->holds($context) || $this->contract->scopeRefuses($context)) {
            return ToolInvocationDecision::allow();
        }

        foreach ($context->history->entries as $entry) {
            if (($entry['tool'] ?? null) === A2aPageMetadataDomainHandler::READ_TOOL && ($entry['outcome'] ?? null) === 'ok') {
                return ToolInvocationDecision::allow();
            }
        }

        return ToolInvocationDecision::deny(self::REASON_NOT_READ, 'Read the page with get_page_content before writing its metadata. Nothing was changed.');
    }
}
