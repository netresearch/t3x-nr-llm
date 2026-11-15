<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use RuntimeException;

/**
 * A run a worker claimed may not start, for a reason waiting would not change
 * ({@see \Netresearch\NrLlm\Service\Agent\RunStartPreparerInterface}). The
 * message names the reason and is recorded with the failed run.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class RunStartRefusedException extends RuntimeException implements NrLlmExceptionInterface {}
