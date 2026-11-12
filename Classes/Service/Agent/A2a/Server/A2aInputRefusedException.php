<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;

/**
 * The error class a run carries when its domain handler refused the input
 * before the run started; the task is REJECTED (spec 051 A2A-SV10).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aInputRefusedException extends A2aException {}
