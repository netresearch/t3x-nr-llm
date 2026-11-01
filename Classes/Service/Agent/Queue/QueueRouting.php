<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Queue;

/**
 * Whether dispatching {@see AgentRunQueuedMessage} leaves this process (ADR-234).
 *
 * On TYPO3's synchronous `default` transport a dispatch IS the execution, so a
 * caller that must not pay for a run has to know before it dispatches. The most
 * specific routing entry decides, as in Symfony Messenger: the class, then a
 * namespace wildcard, then `*`. An absent or unreadable table counts as
 * synchronous: the row waits for the sweep rather than running in the caller.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class QueueRouting
{
    public static function leavesTheProcess(): bool
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $routing = $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] ?? null;
        if (!is_array($routing)) {
            return false;
        }

        $senders = $routing[AgentRunQueuedMessage::class] ?? self::namespaceEntry($routing) ?? $routing['*'] ?? null;

        // With `default` among several senders the dispatch still executes
        // here, so one synchronous sender decides.
        $senders = array_filter(is_array($senders) ? $senders : [$senders], static fn (mixed $sender): bool => is_string($sender) && $sender !== '');

        return $senders !== [] && !in_array('default', $senders, true);
    }

    /**
     * The longest `Vendor\\Package\\*` entry that covers the message.
     *
     * @param array<array-key, mixed> $routing
     */
    private static function namespaceEntry(array $routing): mixed
    {
        $match  = null;
        $length = 0;
        foreach ($routing as $pattern => $senders) {
            if (!is_string($pattern) || !str_ends_with($pattern, '\\*')) {
                continue;
            }

            $prefix = substr($pattern, 0, -1);
            if (strlen($prefix) > $length && str_starts_with(AgentRunQueuedMessage::class, $prefix)) {
                $match  = $senders;
                $length = strlen($prefix);
            }
        }

        return $match;
    }
}
