<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use RuntimeException;

/**
 * The judge gave no usable verdict (ADR-288): it could not be asked, did not
 * answer in time, or answered something that cannot be checked. The message is
 * for the log; the editor reads a sentence of {@see RuleJudgeMessages}.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class JudgeUnavailableException extends RuntimeException implements NrLlmExceptionInterface {}
