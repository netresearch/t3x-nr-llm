<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClientPushSettings;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aPushRegistrationStore;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers the push callback of an accepted delegation (spec 051 A2A-PUSH7,
 * A2A-SEC7, ADR-285), in the order of the specification for a send without
 * an embedded config: the remote task id is already stored with the intent;
 * the local registration is written, then the remote one is created.
 *
 * Nothing registers while `a2a.client.push` is off, without a push site that
 * has an A2A base URL, or when the remote's card does not declare push
 * notifications. A failure never touches the delegation: the run waits by
 * polling, which stays the repair path. A create is sent once and never
 * again, whatever its outcome.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPushRegistrar
{
    public function __construct(
        private A2aClientPushSettings $settings,
        private A2aPushRegistrationStore $registrations,
        private A2aClient $client,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Whether the remote may now post callbacks for the intent's task: its
     * create was acknowledged or has an unknown outcome.
     */
    public function register(A2aRemoteAgent $agent, A2aSendIntent $intent, int $now): bool
    {
        if (!$this->settings->enabled() || !$agent->declaresPushNotifications() || $intent->remoteTaskId === '') {
            return false;
        }

        $url = $this->settings->callbackUrl();
        if ($url === null) {
            $this->logger?->warning('A2A push is on, but a2a.client.pushSite names no site with an A2A base URL; the delegation waits by polling', ['intent' => $intent->uid]);

            return false;
        }

        $registration = null;
        try {
            $registration = $this->registrations->create($intent, $now);
            $result       = $this->client->createTaskPushNotificationConfig($agent, $intent->ownerUid, $intent->remoteTaskId, $registration['configId'], $url, $registration['credential']);
            $this->registrations->settle($registration['uid'], $result->state, $result->remoteConfigId, $result->reason);
        } catch (Throwable $e) {
            // The client throws only before the create leaves (the record or
            // the handle cannot be used now); a failing local write may come
            // after it. Without certainty that nothing left, the outcome is
            // unknown, and the credential stays valid.
            $this->logger?->warning('A2A push registration failed; the delegation waits by polling', ['intent' => $intent->uid, 'exception' => $e::class]);
            if ($registration !== null) {
                try {
                    $this->registrations->settle($registration['uid'], A2aPushRegistrationStore::STATE_OUTCOME_UNKNOWN, '', 'REGISTRATION_FAILED');
                } catch (Throwable) {
                    // The row stays pending, which accepts callbacks too.
                }
            }

            return false;
        }

        return $result->state !== A2aPushRegistrationStore::STATE_REFUSED;
    }

    public function close(int $sendUid): void
    {
        $this->registrations->close($sendUid);
    }
}
