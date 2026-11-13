<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;

/**
 * A domain handler that wants to know when a client's later message to a
 * task of its profile was taken in (spec 051 A2A-MT3). Called once, after the
 * intake committed, in the request; it must not call a model.
 *
 * Only the TCK fixture needs it: it plays the worker for its own runs.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface A2aContinuationObserverInterface
{
    public function continued(string $runUuid, A2aMessage $message): void;
}
