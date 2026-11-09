<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use RuntimeException;

/**
 * A DataHandler hook that throws once a `sys_file_reference` row has been
 * inserted, the way an installation's hook can fail half-way through a write.
 * The parent's version and the new row are in the database by then, so a test
 * sees whether the writer takes its own version back before the exception
 * leaves the tool.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']`
 * and removed again afterwards. Stateless.
 */
final class ThrowsAfterANewFileReferenceHook
{
    public const MESSAGE = 'An installation hook failed after the reference was inserted.';

    public function processDatamap_afterDatabaseOperations(string $status, string $table): void
    {
        if ($table === 'sys_file_reference' && $status === 'new') {
            throw new RuntimeException(self::MESSAGE, 1794144000);
        }
    }
}
