<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use Throwable;

/**
 * Marker for a failure outside any provider call that a retry can fix, such
 * as a store that could not be read for a moment (ADR-228). The queue's
 * failure recovery retries a queued run that hits it instead of
 * dead-lettering it. It is no provider failure: the failure classifier does
 * not know it, so no provider circuit or fallback counts it.
 *
 * Defined in core so a module can mark its failures without core depending
 * on the module (ADR-090).
 *
 * @internal
 */
interface TransientFailureInterface extends Throwable {}
