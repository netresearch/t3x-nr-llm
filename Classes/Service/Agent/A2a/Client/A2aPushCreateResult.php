<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Client;

/**
 * How a `CreateTaskPushNotificationConfig` of the client ended (spec 051
 * A2A-PUSH7, A2A-SEC7): the registration state it leaves, the config id the
 * remote answered (empty unless created), and why.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPushCreateResult
{
    private function __construct(
        public string $state,
        public string $remoteConfigId,
        public string $reason,
    ) {}

    public static function created(string $remoteConfigId): self
    {
        return new self(A2aPushRegistrationStore::STATE_ACTIVE, $remoteConfigId, '');
    }

    public static function refused(string $reason): self
    {
        return new self(A2aPushRegistrationStore::STATE_REFUSED, '', $reason);
    }

    public static function outcomeUnknown(string $reason): self
    {
        return new self(A2aPushRegistrationStore::STATE_OUTCOME_UNKNOWN, '', $reason);
    }
}
