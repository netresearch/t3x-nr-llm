<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\FieldFill;

use RuntimeException;

/**
 * Fails after the row is written, as a third-party hook without a session does.
 */
final class ThrowingDataHandlerHook
{
    public function processDatamap_afterDatabaseOperations(): never
    {
        throw new RuntimeException('No session here', 1798000106);
    }
}
