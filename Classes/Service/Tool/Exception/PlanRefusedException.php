<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Exception;

use Netresearch\NrLlm\Exception\RuntimeException;

/**
 * A writing tool's `plan()` refused the call; the message is the refusal.
 *
 * A control-flow signal inside one tool, not a failure: thrown by the checks
 * of a plan through {@see \Netresearch\NrLlm\Service\Tool\Builtin\RefusesPlanStepsTrait}
 * and caught again by that trait's `planOrRefusal()`, which hands the message
 * back as the refusal string the `plan()` contract returns. It never leaves the
 * tool.
 *
 * @internal
 */
final class PlanRefusedException extends RuntimeException {}
