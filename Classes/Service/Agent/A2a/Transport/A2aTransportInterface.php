<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Transport;

/**
 * Sends one request of the A2A client (spec 051 A2A-S0-3, ADR-279).
 *
 * An implementation checks the URL with the address guard, sends only to the
 * addresses the guard checked, follows no redirect, retries nothing, and
 * never throws for a network failure: it says whether the request may have
 * left ({@see A2aTransportResult}). Whether to try again is the caller's
 * decision, and for a SendMessage whose outcome is unknown the answer is no.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface A2aTransportInterface
{
    public function send(A2aHttpRequest $request): A2aTransportResult;
}
