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
 * as a store that could not be read for a moment (ADR-228). The failure
 * classifier counts it as a connection fault, so a queued run that hits it
 * goes back to the queue instead of being dead-lettered.
 *
 * Defined in core so a module can mark its failures without core depending
 * on the module (ADR-090).
 *
 * @internal
 */
interface TransientFailureInterface extends Throwable {}
