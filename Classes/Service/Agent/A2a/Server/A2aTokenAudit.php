<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The log line of every token issuing, rotation and revocation (ADR-294): who,
 * which record, when. Never the token and never its hash. In switch-user mode
 * the administrator who switched is named too, because the session user is the
 * one that was switched to.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTokenAudit
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function record(string $event, BackendUserAuthentication $actor, int $clientUid): void
    {
        $user = is_array($actor->user) ? $actor->user : [];
        $uid  = $user['uid'] ?? 0;
        $name = $user['username'] ?? '';

        $this->logger->info('A2A client token ' . $event, [
            'event'        => $event,
            'client'       => $clientUid,
            'actor'        => is_numeric($uid) ? (int) $uid : 0,
            'actorName'    => is_string($name) ? $name : '',
            'originalUser' => $actor->getOriginalUserIdWhenInSwitchUserMode(),
            'at'           => gmdate('c'),
        ]);
    }
}
