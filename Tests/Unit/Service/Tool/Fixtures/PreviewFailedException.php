<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Exception;

/**
 * What a test double throws when its preview is asked to fail.
 *
 * A class of its own rather than a bare `RuntimeException`, so a test can name
 * the failure it arranged instead of catching whatever else went wrong on the
 * way — and so the assertion that the exception TEXT never reaches the
 * persisted state has something specific to look for.
 */
final class PreviewFailedException extends Exception {}
