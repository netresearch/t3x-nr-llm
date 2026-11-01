<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use RuntimeException;

/**
 * The term check could not reach a verdict (ADR-238).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class TermCheckFailedException extends RuntimeException implements NrLlmExceptionInterface {}
