<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\Change\ToolChangeCollector;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Lets a listener of a tool call's write event store one write outside the
 * scope of the run's assignment (ADR-295, #1438).
 *
 * The listeners of `AfterAiRecordWrittenEvent` and `AiLabelWriteEvent` run
 * under the scope of the call whose write they hear of: a write outside it
 * rolls the whole call back. An integrator whose listener has to write
 * elsewhere, a log record on a storage page for example, wraps exactly that
 * write:
 *
 *     WriteOutsideTheAssignmentScope::run('audit log of AI writes', fn () => $this->log($event));
 *
 * Only while a scoped call dispatches those events does this change anything.
 * Anywhere else, during a tool's own execution too, the write runs as it would
 * without this class and is held to the scope like any other. Nothing in
 * nr_llm calls it, so no write leaves the scope unless an integrator's code
 * asks for it. The write is still listed in the call's changes and logged as a
 * warning with the reason; the call's rollback takes it back like the rest.
 *
 * @api
 */
final class WriteOutsideTheAssignmentScope
{
    /**
     * Run $write without holding it to the scope of the call, when called from
     * a listener of the call's write event.
     *
     * @template T
     *
     * @param non-empty-string $reason why the write lies outside the scope, for the log
     * @param Closure(): T     $write
     *
     * @return T
     */
    public static function run(string $reason, Closure $write): mixed
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A write outside the assignment scope needs a reason.', 1799990451);
        }

        if (!AssignmentScopeGuard::isDispatchingListeners() || !AssignmentScopeGuard::suspend()) {
            return $write();
        }

        $depth = ToolChangeCollector::depth();
        ToolChangeCollector::arm();
        try {
            return $write();
        } finally {
            while (ToolChangeCollector::depth() > $depth + 1) {
                ToolChangeCollector::take();
            }

            $written = ToolChangeCollector::take(null, false);
            AssignmentScopeGuard::resume();
            AssignmentScopeGuard::allowed($written);
            self::log($reason, $written);
        }
    }

    private static function log(string $reason, ObservedChangeSet $written): void
    {
        GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->warning(
            'A listener wrote outside the assignment scope of an AI run, allowed by the installation: {reason}',
            [
                'reason'  => $reason,
                'records' => array_map(static fn ($change): string => (string) $change->record, $written->changes),
            ],
        );
    }
}
