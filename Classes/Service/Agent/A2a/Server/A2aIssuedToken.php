<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use SensitiveParameter;

/**
 * A token as it was just issued: the only place it exists in clear. It is
 * returned to the actor once and is never stored, logged or put in a flash
 * message.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aIssuedToken
{
    public function __construct(
        public int $clientUid,
        #[SensitiveParameter]
        public string $token,
        public int $expires,
    ) {}
}
