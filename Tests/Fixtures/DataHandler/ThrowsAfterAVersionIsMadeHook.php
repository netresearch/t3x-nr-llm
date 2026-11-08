<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use RuntimeException;

/**
 * A DataHandler hook that throws after a `version` command has run, the way
 * an installation's hook can fail once core has inserted the version of a
 * record and of its inline children (`processCmdmap_postProcess`, called
 * before core merges the copy mapping, TYPO3 13.4 and 14.3). A test sees
 * whether a writer takes back the drafts of a version it never got the uid
 * of.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']`
 * and removed again afterwards. Stateless.
 */
final class ThrowsAfterAVersionIsMadeHook
{
    public const MESSAGE = 'An installation hook failed after the version was made.';

    public function processCmdmap_postProcess(string $command): void
    {
        if ($command === 'version') {
            throw new RuntimeException(self::MESSAGE, 1794144001);
        }
    }
}
