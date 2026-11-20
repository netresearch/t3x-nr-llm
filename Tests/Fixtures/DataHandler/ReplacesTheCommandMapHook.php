<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A `processCmdmap_beforeStart` hook of an installation that replaces the
 * command map, registered after nr_llm's: the map is a public property, and a
 * key it puts there reaches core without passing `DataHandler::start()`.
 */
final class ReplacesTheCommandMapHook
{
    /** @var array<string, array<array-key, array<string, mixed>>>|null */
    public static ?array $cmdmap = null;

    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        if (self::$cmdmap !== null) {
            $dataHandler->cmdmap = self::$cmdmap;
            self::$cmdmap        = null;
        }
    }
}
