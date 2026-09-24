<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A DataHandler hook that runs a {@see ToolDataHandler} from inside another
 * DataHandler's run, so the tool's DataHandler is not the outermost instance.
 *
 * Only then does a failure belong to the run around it: the outer run still
 * has its own remap, reference index and cache steps ahead of it, and a
 * nested instance that swallowed the failure would hide it from the code that
 * started that run. The switch holds the datamap to write; it is cleared
 * before the nested run starts, so the nested run does not start a third.
 */
final class RunsANestedToolDataHandlerHook
{
    /** @var array<string, array<int|string, array<string, mixed>>>|null */
    public static ?array $datamap = null;

    public static function reset(): void
    {
        self::$datamap = null;
    }

    public function processDatamap_afterAllOperations(DataHandler $outer): void
    {
        $datamap = self::$datamap;
        if ($datamap === null) {
            return;
        }

        self::$datamap = null;

        $nested = GeneralUtility::makeInstance(ToolDataHandler::class);
        $nested->start($datamap, [], $outer->BE_USER);
        $nested->process_datamap();
    }
}
