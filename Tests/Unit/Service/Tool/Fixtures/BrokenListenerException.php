<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use RuntimeException;

/**
 * What a consumer's broken event listener throws in the tests.
 *
 * Its own class rather than a bare {@see RuntimeException}: the loop catches
 * `Throwable` and must not fail the run, and a named exception makes the test's
 * subject — a listener failing, not the loop failing — readable at the assertion
 * as well as at the throw.
 */
final class BrokenListenerException extends RuntimeException {}
