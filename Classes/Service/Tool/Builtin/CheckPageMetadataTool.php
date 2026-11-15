<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ObservedState;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataContractRule;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataRequest;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * Checks a proposal of the A2A page metadata skill before it is written
 * (ADR-298, spec 056): it takes exactly the arguments of
 * `update_page_metadata`, so the same map can be checked and written, and
 * judges them with the conditions of the skill's contract (spec 055 PC1 to
 * PC3: the requested row, only requested fields, each value text within its
 * length).
 *
 * A passed check reports the state of the requested row's proposed fields as
 * the run's workspace shows them ({@see ToolResult::withObservedState()}); the
 * runtime keeps its digest and the digest of the arguments in the run's
 * invocation history, and the binding rule lets a write run only when it is
 * the proposal of the latest passed check and the record is still in the
 * state that check observed. A failed check is an error result and counts
 * for nothing.
 *
 * The conditions of version 1 do not depend on the fields' current values;
 * the row is read once, by the observation the digest is computed from. The
 * result names no stored value.
 *
 * Read-only. Outside a run of the page metadata skill there is no request to
 * judge against, and every call fails.
 */
final readonly class CheckPageMetadataTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;

    public function __construct(
        private A2aPageMetadataContractRule $contract,
        private UpdatePageMetadataTool $writeTool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'check_page_metadata',
            'Check a proposal for update_page_metadata before writing it, with exactly the arguments the write '
            . 'will get. Only in a page metadata task: it says whether the proposal meets the task (the requested '
            . 'page row, only the requested fields, each value non-empty text within its length). A write is '
            . 'allowed only for the arguments of the latest passed check, and only while the record is unchanged '
            . 'since that check. Changes nothing.',
            $this->writeTool->getSpec()->parameters,
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $request = $this->contract->requestFor($context->run);
        if ($request === null) {
            return ToolResult::error('This run is not a page metadata task, so there is no request to check a proposal against.');
        }

        if ($request instanceof ToolInvocationDecision) {
            return ToolResult::error(sprintf('The check failed (%s): %s', $request->reason, $request->explanation));
        }

        return $this->judged($request, $arguments, $context);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function judged(A2aPageMetadataRequest $request, array $arguments, ToolExecutionContext $context): ToolResult
    {
        $refusal = $this->contract->proposalRefusal($request, $arguments, $context);
        if ($refusal instanceof ToolInvocationDecision) {
            return ToolResult::error(sprintf('The check failed (%s): %s', $refusal->reason, str_replace(' Nothing was changed.', '', $refusal->explanation)));
        }

        $state = $this->contract->observe($request, $arguments, $context);
        if (!$state instanceof ObservedState) {
            return ToolResult::error('The check failed: the requested record cannot be read in this workspace.');
        }

        return ToolResult::text(sprintf(
            'The check passed for %s. Write it now with update_page_metadata and exactly the same arguments; '
            . 'other arguments, or a write after the record changed, are refused.',
            implode(', ', A2aPageMetadataContractRule::proposedFields($arguments)),
        ))->withObservedState($state);
    }

    public function isEnabledByDefault(): bool
    {
        // It belongs to a writing flow and ships disabled like the writes it
        // precedes; an administrator enables it with update_page_metadata.
        return false;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        // Granted with the write it checks: a configuration that cannot edit
        // has no proposal to check.
        return 'editing';
    }
}
