<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\FieldFill;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Logs an error about a write that landed, as a third-party hook may.
 */
final class NoisyDataHandlerHook
{
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $dataHandler->errorLog[] = 'A hook of an installation has an opinion';
    }
}
