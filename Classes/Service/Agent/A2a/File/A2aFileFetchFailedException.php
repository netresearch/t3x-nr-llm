<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Exception\TransientFailureInterface;
use RuntimeException;

/**
 * A file URL could not be fetched for a reason that may pass: a transport
 * failure, a timeout, or a host that answered 408, 429 or 5xx. The worker's
 * failure recovery retries the run (TransientFailureInterface); the file
 * stays pending and nothing was stored.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aFileFetchFailedException extends RuntimeException implements NrLlmExceptionInterface, TransientFailureInterface {}
