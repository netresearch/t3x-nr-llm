<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Exception;

use Netresearch\NrLlm\Exception\RuntimeException;

/**
 * Base of every A2A client failure (spec 051).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
class A2aException extends RuntimeException {}
